/**
 * `AtomDurableObject` — one generic Durable Object class for every Atom type.
 *
 * Residency shape (production plan §"One generic Durable Object class",
 * MVP spec §"AtomDurableObject lifecycle"):
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
import { loadConfig, META_KEYS } from './config.js';
import { AtomsError, normalizeError } from './errors.js';
import { bootPHP, composeBootCode, guestMemoryBytes, mkdirp, writeGuestFile } from './php-host.js';

/** Wire version of the boot payload handed to the PHP runtime prelude. */
const BOOT_PROTOCOL = 1;

const now = () => Date.now();

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
		/** @type {any} */
		this.php = null;
		/** @type {import('./bridge.js').ParkedCall|null} */
		this.pending = null;
		/** @type {import('./bridge.js').ParkedCall|null} */
		this.parkedTurn = null;

		this.bornAt = now();
		this.turns = 0;
		this.activations = 0;
		this.phpBootMs = /** @type {number|null} */ (null);
		this.runSettled = false;
		/** @type {string|null} */
		this.runError = null;
		/** @type {{code: string, message: string, at: number}|null} */
		this.lastPoison = null;
		/** @type {Promise<void>|null} */
		this.activationPromise = null;
		/** @type {Promise<unknown>|null} */
		this.runPromise = null;

		this.turnChain = Promise.resolve();

		this.bridge = new Bridge({
			ctx,
			env,
			config: this.config,
			identityRef: () => this.identity,
		});
		this.tx = new TransactionMachine({ ctx, config: this.config, host: this });

		this.bridge.ensureSchema();
		this.constructions = this.bridge.bumpConstructions();
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
			if (call.kind === 'info') {
				return envelope({ ok: true, info: this.info(call.type, call.id) });
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

	// ------------------------------------------------------------------ turns

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
		return this.enqueue(async () => {
			await this.ensureActive(type, id);
			const result = await this.runTurn({ ok: true, kind: 'invoke', method, args });
			return { ...result, atom: { type, id } };
		});
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
		}

		if (!next) {
			const why = this.runError ?? 'the PHP runtime exited without parking';
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
				if (this.runSettled) return null;
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
		if (this.php && this.parkedTurn) {
			this.assertIdentity(type, id);
			return;
		}
		if (!this.activationPromise) {
			this.activationPromise = this.ctx
				.blockConcurrencyWhile(() => this.activate(type, id))
				.catch((/** @type {unknown} */ e) => {
					this.activationPromise = null;
					this.discardPhp();
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
	 * @param {string} type
	 * @param {string} id
	 */
	async activate(type, id) {
		const t0 = now();

		this.checkStoredIdentity(type, id);
		this.identity = { type, id };

		const manifest = bundle?.manifest ?? {};
		const atoms = manifest.atoms ?? {};
		if (!Object.prototype.hasOwnProperty.call(atoms, type)) {
			throw new AtomsError('atom_not_found', `atom type ${JSON.stringify(type)} is not in the bundle manifest`);
		}

		const php = await bootPHP({
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

		this.php = php;
		this.pending = null;
		this.parkedTurn = null;
		this.runSettled = false;
		this.runError = null;
		this.tx.reset();

		// One php.run() that never returns until shutdown. It is deliberately not
		// awaited; handlers are attached so a rejection is never unhandled.
		this.runPromise = php.run({ code: composeBootCode(payload, bootstrapPath) }).then(
			(/** @type {any} */ r) => {
				this.runSettled = true;
				this.runError = `the PHP bootstrap returned (exit ${r?.exitCode ?? '?'}): ${String(r?.errors ?? '').slice(0, 2000)}`;
			},
			(/** @type {any} */ e) => {
				this.runSettled = true;
				this.runError = `the PHP bootstrap threw: ${e?.name ?? 'Error'}: ${e?.message ?? String(e)}`;
			}
		);

		const first = await this.waitForPark(this.config.activationTimeoutMs);
		if (!first) {
			throw new AtomsError(
				'internal',
				this.runError ?? 'the PHP bootstrap never parked within the activation budget'
			);
		}
		const parked = await this.serviceParks(first);
		if (!parked) {
			throw new AtomsError('internal', this.runError ?? 'the PHP bootstrap exited before its first turn.await');
		}

		this.markIdentity(type, id);
		this.activations++;
		this.phpBootMs = now() - t0;
		this.log('info', {
			msg: 'atoms.do.activated',
			boot_ms: this.phpBootMs,
			constructions: this.constructions,
		});
	}

	/**
	 * Everything the PHP runtime prelude needs to bootstrap itself.
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
			// Guest paths the host wrote into MEMFS. The prelude indexes these
			// for its bundle-class autoloader (php/README.md §3).
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
	 */
	markIdentity(type, id) {
		this.bridge.metaSet(META_KEYS.type, type);
		this.bridge.metaSet(META_KEYS.id, id);
		this.bridge.metaSet(META_KEYS.bundleFormat, String(this.config.bundleFormat));
		const abi = bundle?.manifest?.abi?.php;
		if (typeof abi === 'string') this.bridge.metaSet(META_KEYS.abiPhp, abi);
		if (this.bridge.metaGet(META_KEYS.createdAt) === null) {
			this.bridge.metaSet(META_KEYS.createdAt, new Date().toISOString());
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
			if (this.runSettled) return null;
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
		const php = this.php;
		this.php = null;
		this.pending = null;
		this.parkedTurn = null;
		this.activationPromise = null;
		this.tx.reset();
		if (php) {
			try {
				php.exit();
			} catch {
				/* the instance is being thrown away; exit() is best effort */
			}
		}
	}

	/**
	 * Shutdown envelope for the parked loop. Not reachable from the MVP router
	 * (there is no destroy route yet); kept because the protocol defines it.
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

	/**
	 * Residency info for `GET /debug/:type/:id/info`. Deliberately does not
	 * activate: it must be usable to observe an evicted residency.
	 *
	 * @param {string} [type]
	 * @param {string} [id]
	 */
	info(type, id) {
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
			constructions: this.constructions,
			activations_this_residency: this.activations,
			turns_this_residency: this.turns,
			resident_ms: now() - this.bornAt,
			php_booted: !!this.php,
			php_boot_ms: this.phpBootMs,
			php_parked: !!this.parkedTurn,
			guest_memory_bytes: this.php ? guestMemoryBytes(this.php) : null,
			sql_calls_this_residency: this.bridge.sqlCalls,
			user_version: Number.isFinite(userVersion) ? userVersion : 0,
			transaction_open: this.tx.open,
			last_poison: this.lastPoison,
			bundle_format: this.config.bundleFormat,
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
