/**
 * `AtomDurableObject` — one generic Durable Object class for every Atom type.
 *
 * Residency shape (production plan §"One generic Durable Object class",
 * runtime spec §"AtomDurableObject lifecycle"):
 *
 *   first event of a residency
 *     -> activation gate inside blockConcurrencyWhile
 *          -> validate/record identity in __atoms_meta          (409 on mismatch)
 *          -> boot PHP, install the tagged doors
 *          -> write runtime + bundle files into MEMFS
 *          -> start ONE php.run() that never returns: it migrates, constructs
 *             the Atom, calls onActivation(), then parks at turn.await
 *   each turn
 *     -> resume the parked loop with {kind:"invoke",...}
 *     -> service any tx.* parks synchronously along the way
 *     -> the loop parks again at turn.await carrying the turn result
 *     -> respond (Cloudflare's output gate holds it until writes are durable)
 *
 * Turns are strictly serialized by a promise-chain mutex. If `php.run()` ever
 * returns or throws, the residency is poisoned: the PHP instance is discarded
 * and the next request re-activates from durable state.
 */
import { DurableObject } from 'cloudflare:workers';

import bundle from './bundle.generated.js';
import { Bridge, TransactionMachine, errorReply } from './bridge.js';
import { CallbackChannel } from './callbacks.js';
import { loadConfig, META_KEYS } from './config.js';
import { AtomsError, normalizeError, retryableFor, statusFor } from './errors.js';
import { bootPHP, composeBootCode, guestMemoryBytes, mkdirp, writeGuestFile } from './php-host.js';
import { TimersHost } from './timers.js';
import {
	WebSocketHost,
	WS_ATTACHMENT_VERSION,
	buildAttachment,
	buildRetiredAttachment,
	isAttachmentCurrent,
	readAttachment,
	attachmentByteLength,
} from './websockets.js';

/** Wire version of the boot payload handed to the guest runtime. */
const BOOT_PROTOCOL = 1;

/**
 * One booted PHP instance and the facts whose lifetime is exactly its own.
 *
 * `settled`/`error` describe this instance's `php.run()`, not "the" run: two
 * instances can have unsettled runs at once, because discarding an instance
 * does not make its run promise settle on the spot.
 *
 * @typedef {{php: any, gen: number, settled: boolean, error: string|null}} PhpInstance
 */

const now = () => Date.now();
const encoder = new TextEncoder();

/**
 * @param {unknown} body
 * @returns {Response}
 */
function envelope(body) {
	return new Response(JSON.stringify(body), {
		status: 200,
		headers: { 'content-type': 'application/json; charset=utf-8' },
	});
}

export class AtomDurableObject extends DurableObject {
	/**
	 * @param {any} ctx DurableObjectState
	 * @param {Record<string, unknown>} env
	 */
	constructor(ctx, env) {
		super(ctx, env);

		this.config = loadConfig(env);
		this.sql = ctx.storage.sql;

		/** @type {{type: string, id: string}|null} */
		this.identity = null;
		/**
		 * Host-minted incarnation UUID for the active Atom, or `null` for a
		 * legacy pre-incarnation Atom. Stored in `__atoms_meta.incarnation` so
		 * sockets accepted before a destroy cannot match a recreated Atom with
		 * the same `{type, id}`.
		 *
		 * @type {string|null}
		 */
		this.incarnation = null;
		/**
		 * Monotonic residency-local epoch bumped on destruction so any
		 * multi-step host work (such as an alarm drain loop) sees that its
		 * Atom was destroyed and stops before touching a recreated incarnation.
		 */
		this.atomEpoch = 0;
		/**
		 * The PHP residency: the booted instance, the generation it was born
		 * into, and whether its one `php.run()` has ended. Null between
		 * `discardPhp()` and the next activation.
		 *
		 * One record rather than a field per fact, because every one of those
		 * facts belongs to a specific instance and only that instance. The
		 * settle handlers close over the record they were attached for
		 * (`watchRun()`), so a `php.run()` that ends late — after the instance
		 * it belonged to was discarded and a fresh one booted — reports onto
		 * its own dead record. Written as fields on `this`, that late settle
		 * landed on whatever was resident at the time, and a healthy new
		 * residency answered with a dead instance's error.
		 *
		 * @type {PhpInstance|null}
		 */
		this.instance = null;
		/** @type {import('./bridge.js').ParkedCall|null} */
		this.pending = null;
		/** @type {import('./bridge.js').ParkedCall|null} */
		this.parkedTurn = null;

		this.bornAt = now();
		this.turns = 0;
		this.activations = 0;
		this.phpBootMs = /** @type {number|null} */ (null);
		/** @type {{code: string, message: string, at: number}|null} */
		this.lastPoison = null;
		/** @type {Promise<void>|null} */
		this.activationPromise = null;

		// Bumped by discardPhp(). Lets a callback in flight across an await
		// (serviceAppCall) detect that the PHP instance it would resume is gone,
		// rather than resuming a dead Emscripten module.
		this.phpGeneration = 0;

		/**
		 * The open callback window's budget, set by `beginWindow()` and cleared
		 * the moment the window closes (end of `runTurn()`, end of `activate()`,
		 * and in `discardPhp()`). Never allowed to outlive its window: the
		 * `exhausted` flag latches for the rest of the turn, so a budget
		 * carried into the NEXT window arrives permanently spent.
		 *
		 * @type {import('./callbacks.js').TurnBudget|null}
		 */
		this.turnBudget = null;

		/**
		 * Collectors minted by `serviceParks()`'s no-window fallback, which is
		 * dead code by construction. They are drained by whichever settle path
		 * encloses the window that produced them (`settlePostTurn()`, or
		 * `activate()`'s own finally), so even the unreachable path cannot leave
		 * a delivery un-awaited across the DO event.
		 *
		 * @type {import('./callbacks.js').TurnCollector[]}
		 */
		this.strayCollectors = [];

		this.turnChain = Promise.resolve();

		this.callbacks = new CallbackChannel({
			config: this.config,
			log: (level, fields) => this.log(level, fields),
			phpGenerationRef: () => this.phpGeneration,
		});
		this.ws = new WebSocketHost({
			ctx,
			config: this.config,
			log: (level, fields) => this.log(level, fields),
			incarnationRef: () => this.activeIncarnation(),
		});
		this.timers = new TimersHost({
			ctx,
			config: this.config,
			log: (level, fields) => this.log(level, fields),
		});
		this.bridge = new Bridge({
			ctx,
			env,
			config: this.config,
			identityRef: () => this.identity,
			callbacks: this.callbacks,
			ws: this.ws,
			timers: this.timers,
			inTransactionRef: () => this.tx.open,
		});
		this.tx = new TransactionMachine({ ctx, config: this.config, host: this, callbacks: this.callbacks });

		// Residency-local WebSocket bookkeeping. None of this is ever
		// persisted: it exists to make a wake path cheap and to give the
		// debug endpoint an honest "this residency" view, never to be the
		// authority on what sockets exist — ctx.getWebSockets() always is.
		this.wsConnectsThisResidency = 0;
		this.wsTurnsThisResidency = 0;
		/** @type {Set<string>} de-dupes webSocketError+webSocketClose firing for one socket */
		this.wsDisconnected = new Set();

		// Zero-write construction for absent/deleted/legacy-scaffolding IDs: read
		// prior constructions only if __atoms_meta already exists, without creating
		// tables or rows. When an Atom already exists (atom_type is recorded in
		// __atoms_meta), persist the incremented construction count so consecutive
		// cold evictions remain observable in GET /debug without booting PHP.
		this.constructions = this.bridge.readConstructions() + 1;
		if (this.bridge.metaGet(META_KEYS.type) !== null) {
			this.bridge.metaSet(META_KEYS.constructions, String(this.constructions));
		}
	}

	// ------------------------------------------------------------- transport

	/**
	 * Internal transport from the Worker entry. Always answers 200 with an
	 * `{ok:...}` envelope; `src/index.js` maps that to the public HTTP shape.
	 *
	 * @param {Request} request
	 * @returns {Promise<Response>}
	 */
	async fetch(request) {
		const url = new URL(request.url);
		// The upgrade route: no JSON body to read (request.json() below would
		// consume/reject a bodyless GET), and the response has to be a real 101
		// carrying `webSocket`, not the {ok:...} envelope every other call here
		// produces. Branches out before anything else touches `request`.
		if (url.pathname === '/ws') {
			return this.handleWsUpgrade(url, request);
		}

		/** @type {any} */
		let call;
		try {
			call = await request.json();
		} catch (e) {
			return envelope({
				ok: false,
				error: { code: 'internal', message: `unreadable DO envelope: ${String(e)}` },
			});
		}

		try {
			if (call.kind === 'invoke') {
				return envelope(await this.invoke(call.type, call.id, call.method, call.args ?? []));
			}
			if (call.kind === 'destroy') {
				return envelope(await this.destroy(call.type, call.id));
			}
			if (call.kind === 'info') {
				return envelope({ ok: true, info: await this.info(call.type, call.id) });
			}
			throw new AtomsError('internal', `unknown DO call kind ${JSON.stringify(call.kind)}`);
		} catch (e) {
			const n = normalizeError(e);
			this.log('error', {
				msg: 'atoms.do.call_failed',
				kind: call?.kind,
				code: n.code,
				error: n.message,
			});
			return envelope({ ok: false, error: { code: n.code, message: n.message } });
		}
	}

	// ------------------------------------------------------------ websockets

	/**
	 * `/ws?call=<json>` — everything `index.js`'s `wsUpgrade()` already
	 * validated (type/id, manifest, the `websocket` flag, params/channels)
	 * arrives packed into `call`; this does ONLY the accept path.
	 * The whole thing runs inside `this.enqueue()`, exactly one turn
	 * mutex slot, so it cannot interleave with any other turn on this
	 * residency.
	 *
	 * @param {URL} url
	 * @param {Request} request
	 * @returns {Promise<Response>}
	 */
	async handleWsUpgrade(url, request) {
		/** @type {any} */
		let call;
		try {
			call = JSON.parse(url.searchParams.get('call') ?? '');
		} catch (e) {
			return wsErrorResponse('invalid_request', `unreadable ws call parameter: ${String(e)}`);
		}
		if (typeof call !== 'object' || call === null || typeof call.type !== 'string' || typeof call.id !== 'string') {
			return wsErrorResponse('invalid_request', 'ws call parameter is missing "type"/"id"');
		}
		const { type, id } = call;
		const params = typeof call.params === 'object' && call.params !== null ? call.params : {};
		const channels = Array.isArray(call.channels) ? call.channels.map(String) : [];

		try {
			const client = await this.withCallbackWindow(
				{ type, id },
				() => {
					// Step A4: host-minted identity. Nothing here is derived from guest
					// memory — id, channels, and the current incarnation UUID are the
					// whole attachment.
					const connId = crypto.randomUUID();
					const attachment = buildAttachment(connId, channels, this.incarnation);
					if (attachmentByteLength(attachment) > this.config.wsMaxAttachmentBytes) {
						throw new AtomsError(
							'invalid_request',
							`this connection's channel list makes its attachment exceed ` +
								`ATOMS_WS_MAX_ATTACHMENT_BYTES (${this.config.wsMaxAttachmentBytes})`
						);
					}

					// Step A5: accept, THEN attach, THEN memoize — in that order, so a
					// frame arriving the instant after accept still finds a readable
					// attachment (a frame sent between acceptWebSocket() and the 101
					// response still reaches the client; the same ordering argument
					// applies to reads).
					const pair = new WebSocketPair();
					const server = pair[1];
					const clientSide = pair[0];
					const tags = [
						this.config.wsConnTagPrefix + connId,
						...channels.map((c) => this.config.wsChannelTagPrefix + c),
					];
					this.ctx.acceptWebSocket(server, tags);
					server.serializeAttachment(attachment);
					this.ws.noteSocket(connId, server);

					this.wsConnectsThisResidency++;
					this.wsTurnsThisResidency++;
					return { server, clientSide, connId };
				},
				async ({ server, clientSide, connId }) => {
					const conn = { id: connId, channels };
					try {
						// Step A6: onConnect fires from exactly this one place
						// — no wake path can ever reach ws.connect.
						const result = await this.runTurn({ ok: true, kind: 'ws.connect', conn, params });
						if (result.ok !== true) {
							// An ok:false envelope (onConnect threw, caught by run_ws_turn())
							// is logged and the connection is KEPT.
							this.log('warning', {
								msg: 'atoms.ws.connect_turn_failed',
								conn: connId,
								code: /** @type {any} */ (result).error?.code,
								error: /** @type {any} */ (result).error?.message,
							});
						}
					} catch (e) {
						// A THROW means the residency was poisoned mid-onConnect: a
						// connection whose onConnect never ran must not exist.
						try {
							server.close(1011, 'atoms: onConnect failed to run');
						} catch {
							/* best effort */
						}
						this.ws.forgetSocket(connId);
						throw e;
					}

					return clientSide;
				},
				(collector) => this.settleDeliveries(collector)
			);
			// Step A7.
			return new Response(null, { status: 101, webSocket: client });
		} catch (e) {
			const n = normalizeError(e);
			// Same rule as `index.js`'s top-level handler: an `internal` message
			// is a host-side detail (a poisoned residency's reason, an
			// Emscripten string) and never goes to the client. It is logged
			// instead, in full, on the side of the connection that owns it.
			if (n.code === 'internal') {
				this.log('error', { msg: 'atoms.ws.upgrade_failed', error: n.message });
				return wsErrorResponse('internal', 'internal error');
			}
			return wsErrorResponse(n.code, n.message);
		}
	}

	/**
	 * A hibernatable socket delivered an inbound frame. Cold or warm residency
	 * — `blockConcurrencyWhile` gates this the same way it gates `fetch()`,
	 * so there is no ws-specific "is it warm?"
	 * check anywhere in this file.
	 *
	 * @param {any} ws
	 * @param {string|ArrayBuffer} message
	 */
	async webSocketMessage(ws, message) {
		const binary = typeof message !== 'string';
		const byteLength = binary ? /** @type {ArrayBuffer} */ (message).byteLength : encoder.encode(message).length;

		await this.wsEvent(
			ws,
			(conn) =>
				binary
					? {
							ok: true,
							kind: 'ws.message',
							conn,
							payload: arrayBufferToBase64(/** @type {ArrayBuffer} */ (message)),
							binary: true,
							encoding: 'base64',
						}
					: { ok: true, kind: 'ws.message', conn, payload: message, binary: false, encoding: 'utf8' },
			{ byteLength, maxBytes: this.config.wsMaxMessageBytes }
		);
	}

	/**
	 * @param {any} ws
	 * @param {number} code
	 * @param {string} reason
	 * @param {boolean} wasClean
	 */
	async webSocketClose(ws, code, reason, wasClean) {
		await this.wsEvent(ws, (conn) => ({ ok: true, kind: 'ws.close', conn, code, reason, wasClean }), {
			forget: true,
			dedupe: true,
		});
	}

	/**
	 * An abrupt client disconnect delivers webSocketClose(1006, false),
	 * not this handler — but a genuine transport error can still fire it, and
	 * it may fire ALONGSIDE webSocketClose for the same socket. The
	 * dedupe set makes at most one ws.close turn ever result.
	 *
	 * @param {any} ws
	 * @param {unknown} error
	 */
	async webSocketError(ws, error) {
		await this.wsEvent(
			ws,
			(conn) => ({ ok: true, kind: 'ws.close', conn, code: 1006, reason: errorReason(error), wasClean: false }),
			{ forget: true, dedupe: true }
		);
	}

	/**
	 * The wake path shared by every hibernatable socket event.
	 *
	 * Attachment validation, retirement/incarnation fencing, identity lookup,
	 * disconnect de-duplication, turn execution, and post-turn timer re-arming
	 * ALL happen inside `this.enqueue()` (the turn mutex) so an event from a
	 * deleted Atom can never race with `destroy()` or touch a newly recreated
	 * Atom under the same ID. Never throws: a failed ws turn is logged, not a
	 * reason to take the residency (or the socket) down.
	 *
	 * @param {any} ws
	 * @param {(conn: {id: string, channels: string[]}) => Record<string, unknown>} buildEnvelope
	 * @param {{forget?: boolean, dedupe?: boolean, byteLength?: number, maxBytes?: number}} [opts]
	 */
	async wsEvent(ws, buildEnvelope, opts = {}) {
		/** @type {import('./callbacks.js').TurnCollector|null} */
		let collector = null;

		await this.enqueue(async () => {
			const att = readAttachment(ws);
			if (!att) {
				this.dropSocket(ws, 1011, 'atoms: unreadable connection attachment');
				return;
			}
			if (att.v !== WS_ATTACHMENT_VERSION) {
				this.dropSocket(ws, 1012, 'atoms: attachment format changed');
				return;
			}
			if (att.retired) {
				this.dropStaleSocket(ws, att.id, 'socket attachment is retired');
				return;
			}

			// A socket can only exist because an upgrade completed an activation,
			// so __atoms_meta is present for a live Atom; absent means the Atom
			// was destroyed (or corrupted). A socket event must NEVER create an
			// Atom, and a socket from a prior incarnation must NEVER touch a
			// recreated one.
			const identity = this.identityFromMeta();
			if (!identity) {
				this.dropStaleSocket(ws, att.id, 'no recorded Atom identity (destroyed)');
				return;
			}
			const currentInc = this.activeIncarnation();
			if (!isAttachmentCurrent(att, currentInc)) {
				this.dropStaleSocket(ws, att.id, 'socket belongs to a destroyed Atom incarnation');
				return;
			}

			if (opts.dedupe) {
				// The Set is residency-lived: it is never cleared per event,
				// because the second of `webSocketError`/`webSocketClose` for one
				// socket arrives AFTER the first has finished, and an entry removed
				// in the first event's `finally` would let the second one through —
				// which is the whole thing this guard exists to stop.
				if (this.wsDisconnected.has(att.id)) return;
				this.wsDisconnected.add(att.id);
			} else if (this.wsDisconnected.has(att.id)) {
				// A frame that arrived after this connection's onDisconnect already
				// ran. Dispatching it would call onMessage() on a connection the
				// Atom has been told is gone — an ordering the API does not allow —
				// so it is dropped rather than delivered late.
				this.log('debug', { msg: 'atoms.ws.message_after_disconnect', conn: att.id });
				return;
			}

			if (opts.maxBytes !== undefined && (opts.byteLength ?? 0) > opts.maxBytes) {
				// An over-cap frame is not dispatched as a turn — the peer must
				// never be left believing a dropped frame was delivered. Checked
				// after stale-socket fencing so a retired socket never emits
				// message_too_big or 1009.
				this.log('warning', { msg: 'atoms.ws.message_too_big', bytes: opts.byteLength });
				try {
					ws.close(1009, 'atoms: message exceeds ATOMS_WS_MAX_MESSAGE_BYTES');
				} catch {
					/* best effort */
				}
				return;
			}

			this.ws.noteSocket(att.id, ws);
			const conn = { id: att.id, channels: att.ch };
			const turnCollector = this.callbacks.newCollector();
			collector = turnCollector;

			try {
				await this.ensureActive(identity.type, identity.id);
				this.wsTurnsThisResidency++;
				this.beginWindow(turnCollector);
				try {
					await this.runTurn(buildEnvelope(conn));
				} finally {
					await this.rearmTimersInTurn();
				}
			} catch (e) {
				// The turn loop never throws out of here: the only way this catches
				// is the same protocol-level failure that would poison an invoke, and
				// a socket event must not take the residency OR the socket down for
				// that.
				const n = normalizeError(e);
				this.log('error', { msg: 'atoms.ws.turn_failed', conn: att.id, code: n.code, error: n.message });
			} finally {
				// The connId -> socket memo is dropped; `wsDisconnected` is NOT —
				// see the dedupe comment above. Forgetting the memo entry is safe
				// because `socketFor()` falls back to the platform's tag index.
				if (opts.forget) this.ws.forgetSocket(att.id);
			}
		});

		// Outside the mutex, exactly like invoke()/handleWsUpgrade(): the next
		// event may start while this turn's dispatch() deliveries are still
		// in flight.
		if (collector) {
			await this.settleDeliveries(collector);
		}
	}

	/**
	 * Drop a stale socket belonging to a destroyed Atom without creating
	 * storage, activating PHP, or mutating any recreated incarnation.
	 *
	 * @param {any} ws
	 * @param {string} connId
	 * @param {string} reason
	 */
	dropStaleSocket(ws, connId, reason) {
		this.log('info', { msg: 'atoms.ws.stale_socket_dropped', conn: connId, reason });
		try {
			ws.serializeAttachment(buildRetiredAttachment(connId));
		} catch {
			/* best effort — the socket may already be closed */
		}
		try {
			ws.close(1000, 'Atom destroyed');
		} catch {
			/* best effort — the socket may already be closed */
		}
	}

	/**
	 * A socket event that cannot be attributed to a valid, current connection
	 * (unreadable/version-mismatched attachment, or no recorded identity). No
	 * turn is dispatched; the socket is closed with the given RFC 6455 code
	 * and the drop is logged.
	 *
	 * @param {any} ws
	 * @param {number} code
	 * @param {string} reason
	 */
	dropSocket(ws, code, reason) {
		this.log('error', { msg: 'atoms.ws.dropped_socket', code, reason });
		try {
			ws.close(code, reason);
		} catch {
			/* best effort — the socket may already be gone */
		}
	}

	/**
	 * Identity for a wake event, read from durable `__atoms_meta` rather than
	 * `this.identity` (set only by `activate()`, which a cold wake has not run
	 * yet).
	 *
	 * @returns {{type: string, id: string}|null}
	 */
	identityFromMeta() {
		const type = this.bridge.metaGet(META_KEYS.type);
		const id = this.bridge.metaGet(META_KEYS.id);
		if (type === null || id === null) return null;
		return { type, id };
	}

	/**
	 * Incarnation token for the current Atom:
	 *   - `string`: modern Atom's host-minted incarnation UUID
	 *   - `null`: legacy pre-incarnation Atom (predates `META_KEYS.incarnation`)
	 *   - `undefined`: no Atom currently exists (absent or destroyed)
	 *
	 * Purely read-only: never creates storage.
	 *
	 * @returns {string|null|undefined}
	 */
	activeIncarnation() {
		if (this.identity !== null) return this.incarnation;
		if (this.identityFromMeta() === null) return undefined;
		return this.bridge.metaGet(META_KEYS.incarnation);
	}

	// ------------------------------------------------------------------ turns

	/**
	 * The one place that mints a turn's delivery collector, takes the turn
	 * mutex, opens the callback window, re-arms timers inside the mutex, and
	 * settles outbound deliveries afterwards:
	 *
	 *   newCollector → enqueue(ensureActive → [mid] → beginWindow → turn → rearmTimers)
	 *   → run.finally(settle).
	 *
	 * Timer re-arming runs INSIDE the turn mutex (strictly after `runTurn()` has
	 * returned and any transaction has committed/rolled back) so a post-turn
	 * alarm re-arm can never race with `destroy()`. Outbound `dispatch()`
	 * delivery settlement stays outside the mutex so slow callbacks do not block
	 * subsequent turns.
	 *
	 * @template T
	 * @param {{type: string, id: string}} identity
	 * @param {(() => any) | null} mid
	 * @param {(mid: any) => Promise<T>} turn
	 * @param {(collector: import('./callbacks.js').TurnCollector) => Promise<void>} settle
	 * @param {{rearmTimers?: boolean}} [opts]
	 * @returns {Promise<T>}
	 */
	withCallbackWindow(identity, mid, turn, settle, opts = {}) {
		const rearmTimers = opts.rearmTimers ?? true;
		const collector = this.callbacks.newCollector();
		const run = this.enqueue(async () => {
			await this.ensureActive(identity.type, identity.id);
			const pre = mid ? mid() : undefined;
			this.beginWindow(collector);
			try {
				return await turn(pre);
			} finally {
				if (rearmTimers) {
					await this.rearmTimersInTurn();
				}
			}
		});
		// Outside the mutex: the next turn may start while this turn's
		// dispatch() deliveries are still in flight.
		return /** @type {Promise<T>} */ (run.finally(() => settle(collector)));
	}

	/**
	 * One turn, serialized against every other turn in this residency.
	 *
	 * @param {string} type
	 * @param {string} id
	 * @param {string} method
	 * @param {unknown[]} args
	 * @returns {Promise<any>} the PHP turn-result envelope, plus `atom`
	 */
	invoke(type, id, method, args) {
		return this.withCallbackWindow(
			{ type, id },
			null,
			async () => {
				const result = await this.runTurn({ ok: true, kind: 'invoke', method, args });
				return { ...result, atom: { type, id } };
			},
			(collector) => this.settleDeliveries(collector)
		);
	}

	/**
	 * Re-arm the Durable Object alarm when this turn touched `__atoms_timers`,
	 * running INSIDE the turn mutex so it cannot race with `destroy()`. Never
	 * turns a committed turn's 200 into a 500: storage failures are logged.
	 *
	 * @returns {Promise<void>}
	 */
	async rearmTimersInTurn() {
		try {
			await this.timers.rearmIfTouched();
		} catch (e) {
			const n = normalizeError(e);
			this.log('error', { msg: 'atoms.do.post_turn_failed', code: n.code, error: n.message });
		}
	}

	/**
	 * Open a callback window for the turn about to run: a fresh budget bound to
	 * `ATOMS_TURN_DEADLINE_MS`, and `collector` as the target for any
	 * `dispatch()` the turn initiates. Called after `ensureActive()` and before
	 * `runTurn()` on every turn path (invoke, ws.*, timer); `activate()` opens
	 * the activation window the same way for `onActivation()`.
	 *
	 * @param {import('./callbacks.js').TurnCollector} collector
	 */
	beginWindow(collector) {
		this.turnBudget = this.callbacks.beginTurn(this.config.turnDeadlineMs, collector);
	}

	/**
	 * Await `collector`'s deliveries, plus any stray collector the dead-code
	 * fallback in `serviceParks()` minted while this window was open. Every
	 * delivery this residency starts is awaited by exactly one of these calls,
	 * inside the DO event that caused it.
	 *
	 * @param {import('./callbacks.js').TurnCollector} collector
	 * @returns {Promise<void>}
	 */
	async settleDeliveries(collector) {
		const strays = this.strayCollectors;
		this.strayCollectors = [];
		await Promise.all([
			this.callbacks.settleTurn(collector),
			...strays.map((c) => this.callbacks.settleTurn(c)),
		]);
	}

	/**
	 * Promise-chain mutex: strictly one turn at a time, in arrival order.
	 *
	 * @template T
	 * @param {() => Promise<T>} fn
	 * @returns {Promise<T>}
	 */
	enqueue(fn) {
		const run = this.turnChain.then(fn, fn);
		this.turnChain = run.then(
			() => undefined,
			() => undefined
		);
		return run;
	}

	/**
	 * Deliver one envelope to the parked loop and collect the next park.
	 *
	 * @param {Record<string, unknown>} turnEnvelope
	 * @returns {Promise<any>}
	 */
	async runTurn(turnEnvelope) {
		const parked = this.parkedTurn;
		if (!parked) {
			throw new AtomsError('internal', 'no parked PHP turn loop for this residency');
		}
		this.parkedTurn = null;

		/** @type {import('./bridge.js').ParkedCall|null} */
		let next;
		try {
			this.pending = null;
			parked.reply(JSON.stringify(turnEnvelope));
			next = await this.serviceParks(this.takePending());
		} catch (e) {
			const n = normalizeError(e);
			this.poison(n.code, n.message);
			throw e instanceof AtomsError ? e : new AtomsError('internal', n.message, { cause: e });
		} finally {
			// The window closes with the turn. A budget left set here would be
			// found by the NEXT window's guest code already spent — and
			// `exhausted` latches, so the damage would be permanent rather than
			// transient (reset on the next beginTurn()). The collector is
			// dropped in the same breath (endWindow): a dispatch
			// that reaches the bridge between windows must hit the "no collector"
			// refusal, not attach to this settled one.
			this.turnBudget = null;
			this.callbacks.endWindow();
		}

		if (!next) {
			const why = this.instance?.error ?? 'the PHP runtime exited without parking';
			this.poison('internal', why);
			throw new AtomsError('internal', 'the PHP runtime terminated mid-turn');
		}

		this.turns++;
		return normalizeTurnResult(next.msg.result);
	}

	/**
	 * Pump park ops until the guest is back at `turn.await`.
	 *
	 * `tx.begin` is handed to the transaction machine, which resumes the guest
	 * inside `transactionSync` and returns once the guest has parked again.
	 * Any other park op at this level is a protocol error and is rejected with
	 * an error reply rather than being silently ignored.
	 *
	 * @param {import('./bridge.js').ParkedCall|null} first
	 * @returns {Promise<import('./bridge.js').ParkedCall|null>} null if the guest exited
	 */
	async serviceParks(first) {
		let p = first;
		for (let steps = 0; ; steps++) {
			if (steps > this.config.maxParkStepsPerTurn) {
				throw new AtomsError(
					'internal',
					`turn exceeded ATOMS_MAX_PARK_STEPS_PER_TURN (${this.config.maxParkStepsPerTurn})`
				);
			}
			if (!p) {
				if (!this.instance || this.instance.settled) return null;
				p = await this.waitForPark(this.config.parkWaitTimeoutMs);
				if (!p) return null;
			}

			const op = typeof p.msg.op === 'string' ? p.msg.op : '(none)';
			if (op === 'turn.await') {
				this.parkedTurn = p;
				return p;
			}
			if (op === 'tx.begin') {
				this.tx.begin(p);
			} else if (op === 'tx.commit' || op === 'tx.rollback') {
				p.reply(errorReply('tx_state', `${op} received with no transaction open`));
			} else if (op === 'app.call') {
				// This is the first `await` inside serviceParks(), and the reason
				// the method is already `async` and already awaited by runTurn().
				//
				// The budget is opened by beginWindow() before any guest code can
				// run — by the turn paths before runTurn(), and by activate()
				// before php.run() starts — so a null one here is a host bug, not
				// an activation-time app() call. It is repaired loudly rather than
				// crashed on: a fresh budget, a fresh collector, and the collector
				// handed to strayCollectors so the enclosing settle path still
				// awaits whatever it collects. Nothing here may be silent — a
				// window opened by accident is the shape of the bug this replaced.
				if (!this.turnBudget) {
					const stray = this.callbacks.newCollector();
					this.strayCollectors.push(stray);
					this.beginWindow(stray);
					this.log('warning', {
						msg: 'atoms.do.callback_window_missing',
						op,
						error: 'an app.call park arrived with no callback window open; opened a fresh one',
					});
				}
				await this.callbacks.serviceAppCall(p, this.turnBudget);
			} else {
				p.reply(errorReply('bad_host_message', `unknown park op ${JSON.stringify(op)}`));
			}
			p = this.takePending();
		}
	}

	// ------------------------------------------------------------ activation

	/**
	 * Ensure this residency has a booted, parked PHP loop for `{type,id}`.
	 *
	 * @param {string} type
	 * @param {string} id
	 */
	async ensureActive(type, id) {
		if (this.instance && this.parkedTurn) {
			this.assertIdentity(type, id);
			return;
		}
		if (!this.activationPromise) {
			this.activationPromise = this.ctx
				.blockConcurrencyWhile(() => this.activate(type, id))
				.catch((/** @type {unknown} */ e) => {
					this.activationPromise = null;
					this.discardPhp();
					if (this.identityFromMeta() === null) {
						this.identity = null;
						this.incarnation = null;
					}
					throw e;
				});
		}
		await this.activationPromise;
		this.assertIdentity(type, id);
	}

	/**
	 * The activation gate. Runs once per residency, inside
	 * `blockConcurrencyWhile`, so no turn is delivered before it completes.
	 *
	 * Activation is itself a callback window (§The turn deadline): the guest
	 * code it runs — the bootstrap, the migrations, and `onActivation()`, which
	 * is customer code on the legal API — may call `app()` and `dispatch()`.
	 * The window is opened BEFORE `php.run()` starts and settled in a `finally`
	 * before this method returns, so activation-time deliveries are awaited
	 * inside the activation event rather than orphaned across it. The settle
	 * runs inside `blockConcurrencyWhile` and is bounded by
	 * `ATOMS_CALLBACK_TIMEOUT_MS`, exactly like the rest of the gate.
	 *
	 * @param {string} type
	 * @param {string} id
	 */
	async activate(type, id) {
		const t0 = now();

		this.checkStoredIdentity(type, id);

		const manifest = bundle?.manifest ?? {};
		const atoms = manifest.atoms ?? {};
		if (!Object.prototype.hasOwnProperty.call(atoms, type)) {
			throw new AtomsError('atom_not_found', `atom type ${JSON.stringify(type)} is not in the bundle manifest`);
		}

		// Preserve existing incarnation across wakes/hibernations. Legacy Atoms
		// (stored atom_type with no incarnation key) keep `null` so their
		// pre-incarnation hibernated sockets remain valid until destruction;
		// every newly created Atom receives a fresh UUID incarnation.
		const existingType = this.bridge.metaGet(META_KEYS.type);
		const existingInc = this.bridge.metaGet(META_KEYS.incarnation);
		const incarnation = existingInc !== null ? existingInc : existingType === null ? crypto.randomUUID() : null;

		this.identity = { type, id };
		this.incarnation = incarnation;

		const php = await bootPHP(bundle.asyncifyLoader, {
			onSync: (msg) => this.bridge.handleSync(msg),
			onPark: (msg, reply) => this.handlePark(msg, reply),
		});

		for (const dir of this.config.guestDirs) mkdirp(php, dir);
		const files = bundle?.files ?? {};
		for (const [path, contents] of Object.entries(files)) {
			writeGuestFile(php, path, /** @type {string} */ (contents));
		}

		const payload = this.bootPayload(type, id);
		writeGuestFile(php, this.config.bootPayloadPath, JSON.stringify(payload));

		const bootstrapPath = typeof manifest.bootstrap === 'string' ? manifest.bootstrap : this.config.bootstrapPath;
		if (!php.fileExists(bootstrapPath)) {
			throw new AtomsError(
				'internal',
				`the bundle does not contain the PHP bootstrap at ${bootstrapPath}`
			);
		}

		// Materialize host tables right before guest code runs (never in the
		// constructor) so migrations can read/write PRAGMA user_version and
		// onActivation() can use timers while absent/deleted IDs stay empty.
		this.bridge.ensureSchema();
		this.bridge.metaSet(META_KEYS.constructions, String(this.constructions));

		/** @type {PhpInstance} */
		const instance = { php, gen: this.phpGeneration, settled: false, error: null };
		this.instance = instance;
		this.pending = null;
		this.parkedTurn = null;
		this.tx.reset();

		// The activation window opens here — before a single line of guest code
		// has run, so `onActivation()`'s app()/dispatch() find a budget and a
		// collector already waiting for them.
		const activationCollector = this.callbacks.newCollector();
		this.beginWindow(activationCollector);

		try {
			// One php.run() that never returns until shutdown. It is deliberately not
			// awaited; handlers are attached so a rejection is never unhandled.
			this.watchRun(instance, php.run({ code: composeBootCode(payload, bootstrapPath) }));

			const first = await this.waitForPark(this.config.activationTimeoutMs);
			if (!first) {
				throw new AtomsError(
					'internal',
					instance.error ?? 'the PHP bootstrap never parked within the activation budget'
				);
			}
			// Re-stamp the activation budget's clock to NOW. The window was opened
			// before php.run() so it would exist before any guest code, but wasm
			// boot and the migrations run inside php.run() ahead of onActivation,
			// and they must not be charged to onActivation's app() budget. No park
			// happens during boot or migrations (the SQL bridge is a sync op), so
			// this first park is the first guest checkpoint AFTER them — either
			// onActivation's own app.call or its final turn.await. Resetting
			// startedAt here hands onActivation a FULL, fresh ATOMS_TURN_DEADLINE_MS
			// exactly as §The turn deadline claims, instead of whatever boot left.
			if (this.turnBudget) this.turnBudget.startedAt = now();
			const parked = await this.serviceParks(first);
			if (!parked) {
				throw new AtomsError('internal', instance.error ?? 'the PHP bootstrap exited before its first turn.await');
			}

			this.markIdentity(type, id, incarnation);
			this.activations++;
			this.phpBootMs = now() - t0;
			this.log('info', {
				msg: 'atoms.do.activated',
				boot_ms: this.phpBootMs,
				constructions: this.constructions,
			});
		} finally {
			// `finally`, for the same reason `invoke()` settles with `.finally`:
			// a job dispatched before a failing activation is as durable as a
			// non-transactional write and must still be awaited. Settlement
			// itself never rejects, so this cannot mask the real activation
			// error.
			await this.settleDeliveries(activationCollector);
			this.turnBudget = null;
			// Deliveries are settled; drop the collector so nothing can attach to
			// it between the activation window and the first turn window.
			this.callbacks.endWindow();
		}
	}

	/**
	 * Everything the guest runtime needs to bootstrap itself.
	 *
	 * @param {string} type
	 * @param {string} id
	 */
	bootPayload(type, id) {
		return {
			protocol: BOOT_PROTOCOL,
			bundle_format: this.config.bundleFormat,
			host: 'cloudflare-do',
			atom: { type, id },
			manifest: bundle?.manifest ?? {},
			// Guest paths the host wrote into MEMFS. The guest runtime indexes
			// these for its bundle-class autoloader (php/README.md §3).
			files: Object.keys(bundle?.files ?? {}),
			paths: {
				boot_payload: this.config.bootPayloadPath,
				bootstrap: this.config.bootstrapPath,
				runtime: this.config.runtimeDir,
				core: this.config.coreDir,
			},
			residency: {
				constructions: this.constructions,
				do_id: String(this.ctx.id),
			},
			debug: this.config.debugEndpoints,
		};
	}

	/**
	 * Identity check against durable metadata. A DO name is derived from
	 * `type + "\n" + id`, so a mismatch means the caller addressed the wrong
	 * object — 409, and no PHP dispatch.
	 *
	 * @param {string} type
	 * @param {string} id
	 */
	checkStoredIdentity(type, id) {
		const storedType = this.bridge.metaGet(META_KEYS.type);
		const storedId = this.bridge.metaGet(META_KEYS.id);
		if (storedType !== null && storedType !== type) {
			throw new AtomsError(
				'identity_conflict',
				`this Atom is ${JSON.stringify(storedType)}, not ${JSON.stringify(type)}`
			);
		}
		if (storedId !== null && storedId !== id) {
			throw new AtomsError(
				'identity_conflict',
				`this Atom's id is ${JSON.stringify(storedId)}, not ${JSON.stringify(id)}`
			);
		}
	}

	/**
	 * @param {string} type
	 * @param {string} id
	 * @param {string|null} [incarnation]
	 */
	markIdentity(type, id, incarnation = null) {
		this.bridge.metaSet(META_KEYS.type, type);
		this.bridge.metaSet(META_KEYS.id, id);
		this.bridge.metaSet(META_KEYS.bundleFormat, String(this.config.bundleFormat));
		this.bridge.metaSet(META_KEYS.constructions, String(this.constructions));
		const abi = bundle?.manifest?.abi?.php;
		if (typeof abi === 'string') this.bridge.metaSet(META_KEYS.abiPhp, abi);
		if (this.bridge.metaGet(META_KEYS.createdAt) === null) {
			this.bridge.metaSet(META_KEYS.createdAt, new Date().toISOString());
		}
		if (typeof incarnation === 'string' && incarnation !== '') {
			this.bridge.metaSet(META_KEYS.incarnation, incarnation);
		}
	}

	/**
	 * @param {string} type
	 * @param {string} id
	 */
	assertIdentity(type, id) {
		if (this.identity && (this.identity.type !== type || this.identity.id !== id)) {
			throw new AtomsError(
				'identity_conflict',
				`this residency serves ${this.identity.type}/${this.identity.id}`
			);
		}
	}

	// ----------------------------------------------------------------- parks

	/**
	 * The '~' door. Records the park; the servicing loops decide what to do.
	 *
	 * @param {string} raw message with the tag byte stripped
	 * @param {(reply: string) => void} reply
	 */
	handlePark(raw, reply) {
		/** @type {any} */
		let msg;
		try {
			msg = JSON.parse(raw);
		} catch (e) {
			msg = { op: '(unparseable)', parse_error: String(e) };
		}
		if (typeof msg !== 'object' || msg === null) msg = { op: '(non-object)' };
		this.pending = { msg, reply };
	}

	/**
	 * @returns {import('./bridge.js').ParkedCall|null}
	 */
	takePending() {
		const p = this.pending;
		this.pending = null;
		return p;
	}

	/**
	 * Put a park back for the next servicing loop to take. Used by the
	 * transaction machine when the guest reaches `turn.await` from inside an
	 * open transaction: that park belongs to `serviceParks()`, not to it.
	 *
	 * @param {import('./bridge.js').ParkedCall} parked
	 */
	restorePending(parked) {
		if (this.pending) {
			throw new AtomsError('internal', 'cannot restore a park: another one is already pending');
		}
		this.log('error', {
			msg: 'atoms.do.transaction_abandoned',
			error: 'the guest reached the turn boundary inside an open transaction; it was rolled back',
		});
		this.pending = parked;
	}

	/**
	 * Attach the settle handlers for one instance's `php.run()`.
	 *
	 * The handlers close over `instance`, which is the whole point: discarding
	 * an instance does not settle its run promise, so a run can end at any
	 * later moment — including after a fresh instance has booted into the same
	 * Durable Object. It reports onto the record it belonged to, and the
	 * residency reads `this.instance`, so a dead instance can no longer file
	 * its cause of death against a live one.
	 *
	 * @param {PhpInstance} instance
	 * @param {Promise<any>} run
	 */
	watchRun(instance, run) {
		run.then(
			(/** @type {any} */ r) =>
				this.settleRun(
					instance,
					`the PHP bootstrap returned (exit ${r?.exitCode ?? '?'}): ${String(r?.errors ?? '').slice(0, 2000)}`
				),
			(/** @type {any} */ e) =>
				this.settleRun(instance, `the PHP bootstrap threw: ${e?.name ?? 'Error'}: ${e?.message ?? String(e)}`)
		);
	}

	/**
	 * @param {PhpInstance} instance
	 * @param {string} error
	 */
	settleRun(instance, error) {
		instance.settled = true;
		instance.error = error;
		if (this.instance !== instance) {
			// Not a failure: a discarded instance's run ends when it ends. It is
			// logged because this is the moment that used to corrupt the state
			// of whatever had booted in its place, and a silent one would leave
			// nothing to point at if it ever does again.
			this.log('info', { msg: 'atoms.do.stale_run_settled', gen: instance.gen, error });
		}
	}

	/**
	 * Wait for the guest to park. Used where the park is produced by php.run()'s
	 * own async progression (activation) rather than by a synchronous resume.
	 *
	 * @param {number} timeoutMs
	 * @returns {Promise<import('./bridge.js').ParkedCall|null>}
	 */
	async waitForPark(timeoutMs) {
		const deadline = now() + timeoutMs;
		for (let polls = 0; polls <= this.config.activationMaxPolls; polls++) {
			if (this.pending) return this.takePending();
			// No instance is the same answer as a settled one: no park is coming.
			if (!this.instance || this.instance.settled) return null;
			if (now() > deadline) {
				throw new AtomsError('internal', `the PHP runtime did not park within ${timeoutMs}ms`);
			}
			await new Promise((r) => setTimeout(r, this.config.activationPollMs));
		}
		throw new AtomsError(
			'internal',
			`the PHP runtime did not park within ATOMS_ACTIVATION_MAX_POLLS (${this.config.activationMaxPolls})`
		);
	}

	// -------------------------------------------------------------- residency

	/**
	 * Discard the PHP instance; the next request re-activates from durable
	 * state. Everything the last turn wrote is already durable.
	 *
	 * @param {string} code
	 * @param {string} message
	 */
	poison(code, message) {
		this.lastPoison = { code, message, at: now() };
		this.log('error', { msg: 'atoms.do.residency_poisoned', code, error: message });
		this.discardPhp();
	}

	discardPhp() {
		// Detached first: the instance stops being this residency's the moment
		// it is discarded, and its run promise may still be pending — whatever
		// it eventually reports belongs to `discarded`, not to whatever boots
		// next.
		const discarded = this.instance;
		this.instance = null;
		this.pending = null;
		this.parkedTurn = null;
		this.activationPromise = null;
		// Whatever window was open died with the PHP instance. Leaving the
		// budget behind would hand the next residency's first guest code a
		// latched, already-exhausted one; leaving the collector behind would let
		// a stray dispatch attach a delivery to a window that no longer exists.
		this.turnBudget = null;
		this.callbacks.endWindow();
		this.tx.reset();
		// The connId -> socket memo is residency-local and must not outlive
		// the PHP instance it was warmed for — but the sockets themselves are
		// NOT closed: poisoning is recoverable, and a socket stays open
		// across it exactly like it stays open across an eviction.
		this.ws.clearMemo();
		// Invalidates any serviceAppCall() awaiting a fetch with this
		// generation captured: its reply is dropped rather than resuming a
		// discarded PHP instance.
		this.phpGeneration++;
		if (discarded) {
			try {
				discarded.php.exit();
			} catch {
				/* the instance is being thrown away; exit() is best effort */
			}
		}
	}

	// ------------------------------------------------------------ destruction

	/**
	 * Pure read-only inspection of whether an Atom currently exists on this DO
	 * (persisted customer/meta/timer data, platform alarm, or live non-retired
	 * sockets). Never creates SQLite tables, rows, or alarms, and never boots PHP.
	 */
	async inspectAtomState() {
		const storage = this.bridge.inspectStorage();
		const alarmAtMs = await this.ctx.storage.getAlarm();
		const hasAlarm = alarmAtMs !== null && alarmAtMs !== undefined;
		const rawSockets = this.ctx.getWebSockets();
		const rawSocketCount = rawSockets.length;
		const liveSocketCount = rawSockets.filter((/** @type {any} */ ws) => {
			const att = readAttachment(ws);
			return !att || !att.retired;
		}).length;

		return {
			storage,
			alarmAtMs: hasAlarm ? Number(alarmAtMs) : null,
			hasAlarm,
			rawSocketCount,
			liveSocketCount,
			hasAnyStorage: storage.hasAnyStorage,
			exists: storage.hasAtomData || hasAlarm || liveSocketCount > 0,
		};
	}

	/**
	 * Explicitly destroy an Atom and all of its local durable state.
	 *
	 * Ordered on the turn mutex against invokes, upgrades, socket callbacks,
	 * timer dispatches, and alarm re-arming. Runs `onDeactivation()` best-effort
	 * ONLY for a healthy resident PHP guest under the existing turn limits,
	 * then strictly after it settles discards PHP, retires and closes all
	 * sockets with code `1000` and reason `Atom destroyed`, clears timers and
	 * the platform alarm, awaits `ctx.storage.deleteAll()`, and resets host
	 * residency state so immediate same-ID reuse starts fresh.
	 *
	 * @param {string} type
	 * @param {string} id
	 * @returns {Promise<{ok: true, destroyed: boolean}>}
	 */
	async destroy(type, id) {
		/** @type {import('./callbacks.js').TurnCollector|null} */
		let deactivateCollector = null;

		const outcome = await this.enqueue(async () => {
			this.checkStoredIdentity(type, id);

			const state = await this.inspectAtomState();
			const hasResidentPhp =
				this.instance !== null && !this.instance.settled && this.parkedTurn !== null && !this.tx.open;
			const hasAnyPhp = this.instance !== null || this.parkedTurn !== null;

			if (!state.exists && !hasAnyPhp) {
				// Absent ID or legacy constructor-only metadata: never boot PHP.
				// Discard any leftover constructor-only scaffolding so storage
				// stays genuinely empty, and report destroyed: false.
				if (state.rawSocketCount > 0) {
					this.retireAndCloseAllSockets();
				}
				if (state.hasAnyStorage || state.hasAlarm) {
					await this.wipeStorage();
				}
				this.resetDestroyedHostState();
				return { ok: true, destroyed: false };
			}

			// Destruction owns the close handshake for every socket open when
			// teardown starts — retire and close with 1000 "Atom destroyed"
			// BEFORE running resident onDeactivation() so guest code cannot
			// override the wire close frame with a custom code/reason.
			this.retireAndCloseAllSockets();

			if (hasResidentPhp) {
				const collector = this.callbacks.newCollector();
				deactivateCollector = collector;
				this.beginWindow(collector);
				try {
					const result = await this.runTurn({ ok: true, kind: 'deactivate' });
					if (result.ok !== true) {
						this.log('warning', {
							msg: 'atoms.do.deactivation_failed',
							code: /** @type {any} */ (result).error?.code,
							error: /** @type {any} */ (result).error?.message,
						});
					}
				} catch (e) {
					const n = normalizeError(e);
					this.log('error', {
						msg: 'atoms.do.deactivation_failed',
						code: n.code,
						error: n.message,
					});
				}
			}

			// Final cleanup runs strictly AFTER onDeactivation() settles so
			// nothing the hook wrote or scheduled can survive.
			this.atomEpoch++;
			this.discardPhp();
			this.retireAndCloseAllSockets();
			this.timers.reset();
			await this.wipeStorage();
			this.resetDestroyedHostState();

			this.log('info', {
				msg: 'atoms.do.destroyed',
				atom: { type, id },
				was_resident: hasResidentPhp,
			});

			return { ok: true, destroyed: true };
		});

		if (deactivateCollector) {
			await this.settleDeliveries(deactivateCollector);
		}

		return outcome;
	}

	/**
	 * Mark every attached socket retired (including hibernated sockets and
	 * older/unreadable attachment formats) and close with 1000 "Atom destroyed".
	 */
	retireAndCloseAllSockets() {
		const sockets = this.ctx.getWebSockets();
		for (const ws of sockets) {
			const att = readAttachment(ws);
			if (att?.retired) continue;
			try {
				ws.serializeAttachment(buildRetiredAttachment(att?.id));
			} catch {
				/* best effort — the socket may already be closed */
			}
			try {
				ws.close(1000, 'Atom destroyed');
			} catch {
				/* best effort — the socket may already be closed */
			}
		}
		this.ws.clearMemo();
	}

	/**
	 * Delete the platform alarm and all Durable Object SQLite/KV storage.
	 * Awaited before `destroy()` reports success so storage failures surface
	 * through the error envelope.
	 */
	async wipeStorage() {
		await this.ctx.storage.deleteAlarm();
		await this.ctx.storage.deleteAll();
	}

	/**
	 * Clear all host-side residency state after destruction so immediate reuse
	 * of the same `{type, id}` starts completely fresh.
	 */
	resetDestroyedHostState() {
		this.atomEpoch++;
		this.identity = null;
		this.incarnation = null;
		this.turns = 0;
		this.activations = 0;
		this.phpBootMs = null;
		this.lastPoison = null;
		this.wsConnectsThisResidency = 0;
		this.wsTurnsThisResidency = 0;
		this.wsDisconnected.clear();
		this.ws.clearMemo();
		this.ws.resetCounters();
		this.timers.reset();
		this.callbacks.resetCounters();
		this.bridge.sqlCalls = 0;
		this.constructions = 1;
	}

	/**
	 * Shutdown envelope for the parked loop. Kept because the protocol defines it.
	 */
	async shutdown() {
		if (!this.parkedTurn) return { ok: true, shutdown: 'not_resident' };
		return this.enqueue(async () => {
			const parked = this.parkedTurn;
			this.parkedTurn = null;
			if (!parked) return { ok: true, shutdown: 'not_resident' };
			this.pending = null;
			parked.reply(JSON.stringify({ ok: true, kind: 'shutdown' }));
			this.discardPhp();
			return { ok: true, shutdown: 'sent' };
		});
	}

	// ------------------------------------------------------------------ alarm

	/**
	 * The Durable Object alarm handler: the platform calls this
	 * when this residency's stored alarm time has passed, cold or warm,
	 * evicted or not — this IS the mechanism that wakes an evicted residency
	 * to fire a due timer without any HTTP request ever reaching it. Must
	 * never throw: Cloudflare retries a throwing alarm, and the
	 * delete-before-dispatch rule in `runAlarm()` already makes a retry safe,
	 * but the contract this file keeps everywhere else is "report, don't
	 * throw" — `alarm()` keeps it too.
	 */
	async alarm() {
		try {
			await this.runAlarm();
		} catch (e) {
			const n = normalizeError(e);
			this.log('error', { msg: 'atoms.do.alarm_failed', code: n.code, error: n.message });
		}
	}

	/**
	 * Drain due timers for the current Atom incarnation, strictly serialized
	 * with `destroy()` on the turn mutex. Every iteration re-verifies identity,
	 * incarnation, and `atomEpoch` inside `this.enqueue()` before reading or
	 * deleting a due row or dispatching `onTimer()`, and the final
	 * `rearmForAlarm()` runs inside `this.enqueue()` under the same check.
	 *
	 * @returns {Promise<void>}
	 */
	async runAlarm() {
		const cap = this.config.timersMaxPerAlarm;
		let drained = 0;
		// Bind to the residency epoch at handler entry — BEFORE the first
		// enqueue() — so an alarm queued behind an in-flight destroy() and a
		// replacement Atom B's reuse can never bind to B's epoch or touch B.
		const entryEpoch = this.atomEpoch;
		/** @type {string|null|undefined} */
		let boundIncarnation = undefined;

		while (drained < cap) {
			/** @type {{stop?: boolean, done?: boolean, collector?: import('./callbacks.js').TurnCollector}} */
			const step = await this.enqueue(async () => {
				if (this.atomEpoch !== entryEpoch) {
					return { stop: true };
				}

				const identity = this.identityFromMeta();
				if (!identity) {
					if (drained === 0) {
						this.log('info', {
							msg: 'atoms.do.alarm_ignored_no_atom',
							reason: 'alarm fired with no recorded __atoms_meta identity',
						});
					}
					return { stop: true };
				}

				const currentInc = this.activeIncarnation();
				if (boundIncarnation === undefined) {
					boundIncarnation = currentInc;
				} else if (currentInc !== boundIncarnation) {
					return { stop: true };
				}

				// Host-side query inside the mutex, WITHOUT booting PHP when
				// nothing is due.
				const due = this.timers.dueRows(now(), 1);
				if (due.length === 0) {
					return { done: true };
				}

				const row = due[0];
				// At-most-once: delete BEFORE dispatch, atomically inside the
				// same mutex slot as the turn so destroy() cannot interleave
				// between row selection, deletion, and dispatch.
				this.timers.deleteRow(row.name);
				this.timers.noteFired();

				const collector = this.callbacks.newCollector();
				try {
					await this.ensureActive(identity.type, identity.id);
					this.beginWindow(collector);
					const result = await this.runTurn({ ok: true, kind: 'timer', name: row.name });
					if (result.ok !== true) {
						this.log('warning', {
							msg: 'atoms.do.timer_turn_failed',
							name: row.name,
							code: /** @type {any} */ (result).error?.code,
							error: /** @type {any} */ (result).error?.message,
						});
					}
				} catch (e) {
					const n = normalizeError(e);
					this.log('error', {
						msg: 'atoms.do.alarm_turn_failed',
						name: row.name,
						code: n.code,
						error: n.message,
					});
				}

				return { collector };
			});

			if (step.stop) return;
			if (step.done) break;

			if (step.collector) {
				await this.settleDeliveries(step.collector);
			}
			drained++;
		}

		// Final re-arm inside the turn mutex, ONLY if the same Atom still exists.
		await this.enqueue(async () => {
			if (this.atomEpoch !== entryEpoch) return;
			const identity = this.identityFromMeta();
			if (!identity) return;
			if (boundIncarnation !== undefined && this.activeIncarnation() !== boundIncarnation) return;
			await this.timers.rearmForAlarm();
		});
	}

	/**
	 * Residency info for `GET /debug/:type/:id/info`. `info()` itself performs
	 * no writes and never activates PHP: safe on an empty database, leaves
	 * absent/deleted/scaffolding IDs with zero tables or alarms, and usable to
	 * observe evicted or destroyed residencies (cold DO construction persists
	 * `__atoms_meta.constructions` only when `atom_type` already exists).
	 *
	 * @param {string} [type]
	 * @param {string} [id]
	 */
	async info(type, id) {
		const state = await this.inspectAtomState();
		const userVersion = Number(this.bridge.metaGet(META_KEYS.userVersion) ?? '0');
		return {
			do_id: String(this.ctx.id),
			requested: { type: type ?? null, id: id ?? null },
			stored: {
				type: this.bridge.metaGet(META_KEYS.type),
				id: this.bridge.metaGet(META_KEYS.id),
				created_at: this.bridge.metaGet(META_KEYS.createdAt),
				abi_php: this.bridge.metaGet(META_KEYS.abiPhp),
			},
			storage: {
				exists: state.exists,
				has_meta_table: state.storage.hasMetaTable,
				has_timers_table: state.storage.hasTimersTable,
				tables: state.storage.tables,
				user_tables: state.storage.userTables,
				meta_keys: state.storage.metaKeys,
				alarm_at_ms: state.alarmAtMs,
				database_size: state.storage.databaseSize,
			},
			constructions: this.constructions,
			activations_this_residency: this.activations,
			turns_this_residency: this.turns,
			resident_ms: now() - this.bornAt,
			php_booted: !!this.instance,
			php_boot_ms: this.phpBootMs,
			php_parked: !!this.parkedTurn,
			guest_memory_bytes: this.instance ? guestMemoryBytes(this.instance.php) : null,
			sql_calls_this_residency: this.bridge.sqlCalls,
			user_version: Number.isFinite(userVersion) ? userVersion : 0,
			transaction_open: this.tx.open,
			last_poison: this.lastPoison,
			bundle_format: this.config.bundleFormat,
			callback_channel: this.callbacks.state,
			callback_calls_this_residency: this.callbacks.callbackCalls,
			dispatches_this_residency: this.callbacks.dispatches,
			dispatch_failures_this_residency: this.callbacks.dispatchFailures,
			turn_deadline_ms: this.config.turnDeadlineMs,
			ws: this.ws.debugBlock(this.wsConnectsThisResidency, this.wsTurnsThisResidency),
			timers: this.timers.debugBlock(),
		};
	}

	/**
	 * @param {string} level
	 * @param {Record<string, unknown>} fields
	 */
	log(level, fields) {
		console.log(
			JSON.stringify({
				ts: new Date().toISOString(),
				level,
				source: 'host',
				atom: this.identity,
				...fields,
			})
		);
	}
}

/**
 * The `/ws` route's own error envelope: `{"error":{"code","message",
 * "retryable"}}`, the same shape `index.js`'s `errorResponse()` builds for
 * `/invoke` — required here because an upgrade response is returned straight
 * from the DO stub, never through `callDurableObject()`'s JSON unwrapping.
 *
 * @param {string} code
 * @param {string} message
 * @returns {Response}
 */
function wsErrorResponse(code, message) {
	return new Response(JSON.stringify({ error: { code, message, retryable: retryableFor(code) } }), {
		status: statusFor(code),
		headers: { 'content-type': 'application/json; charset=utf-8' },
	});
}

/**
 * @param {ArrayBuffer} buf
 * @returns {string}
 */
function arrayBufferToBase64(buf) {
	const bytes = new Uint8Array(buf);
	let binary = '';
	for (let i = 0; i < bytes.length; i++) binary += String.fromCharCode(bytes[i]);
	return btoa(binary);
}

/**
 * @param {unknown} error
 * @returns {string}
 */
function errorReason(error) {
	if (error instanceof Error) return error.message;
	return String(error ?? 'unknown error');
}

/**
 * Validate the turn-result envelope PHP parked with.
 *
 * @param {unknown} result
 * @returns {{ok: true, result: unknown}|{ok: false, error: {code: string, message: string, class?: string}}}
 */
function normalizeTurnResult(result) {
	if (typeof result !== 'object' || result === null) {
		return {
			ok: false,
			error: { code: 'internal', message: 'the PHP turn loop parked without a turn result' },
		};
	}
	const r = /** @type {any} */ (result);
	if (r.ok === true) return { ok: true, result: r.result ?? null };
	if (r.ok === false && typeof r.error === 'object' && r.error !== null) {
		const code = typeof r.error.code === 'string' ? r.error.code : 'internal';
		const message = typeof r.error.message === 'string' ? r.error.message : 'unspecified PHP error';
		/** @type {{code: string, message: string, class?: string}} */
		const error = { code, message };
		if (typeof r.error.class === 'string') error.class = r.error.class;
		return { ok: false, error };
	}
	return {
		ok: false,
		error: { code: 'internal', message: 'the PHP turn loop parked with a malformed turn result' },
	};
}
