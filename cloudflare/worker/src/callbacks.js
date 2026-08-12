/**
 * The signed callback channel: `app()` (park op `app.call`) and `dispatch()`
 * (sync op `dispatch.enqueue`) both cross here. Ported from the approved
 * design doc's §1/§2/§4/§6.
 *
 * The opaque-body invariant (design §1.1) is load-bearing for this whole file:
 * `body` arrives from the guest as an already-`json_encode()`d string and is
 * never `JSON.parse`d or re-encoded. `TextEncoder.encode()` turns it into the
 * exact bytes that are signed and sent; the response text is relayed to the
 * guest verbatim. No JS value is ever derived from a callback body's contents.
 *
 * One instance per DO, constructed alongside `Bridge` and `TransactionMachine`
 * in `atom-do.js`. `Bridge` holds a reference for `dispatch.enqueue`;
 * `TransactionMachine` holds one for the commit/rollback hooks; `atom-do.js`
 * drives `beginTurn`/`serviceAppCall`/`settleTurn`.
 */
import { base64ToBytes } from './config.js';
import { AtomsError } from './errors.js';

/** @param {Record<string, unknown>} extra */
function ok(extra = {}) {
	return JSON.stringify({ ok: true, ...extra });
}

/**
 * @param {string} code
 * @param {string} message
 * @param {Record<string, unknown>} [extra]
 */
function fail(code, message, extra = {}) {
	return JSON.stringify({ ok: false, error: { code, message, ...extra } });
}

const encoder = new TextEncoder();

// The fixed 16-byte PKCS8 DER prefix for a raw 32-byte Ed25519 seed. Verified
// against workerd's WebCrypto and node:crypto (design doc §0.1 M1, §6.1).
const PKCS8_PREFIX = new Uint8Array([
	0x30, 0x2e, 0x02, 0x01, 0x00, 0x30, 0x05, 0x06, 0x03, 0x2b, 0x65, 0x70, 0x04, 0x22, 0x04, 0x20,
]);

// A JS string can hold a lone UTF-16 surrogate, which TextEncoder silently
// replaces with U+FFFD — signed would still equal sent, but sent would no
// longer equal what PHP built. PHP's json_encode() cannot emit one (design
// §1.1), so this only ever fires on a prelude bug.
const LONE_SURROGATE_RE = /[\uD800-\uDBFF](?![\uDC00-\uDFFF])|(?<![\uD800-\uDBFF])[\uDC00-\uDFFF]/;

/**
 * @param {Uint8Array} bytes
 * @returns {string}
 */
function bytesToBase64(bytes) {
	let binary = '';
	for (let i = 0; i < bytes.length; i++) binary += String.fromCharCode(bytes[i]);
	return btoa(binary);
}

/**
 * @param {Uint8Array} bytes
 * @returns {string} lowercase hex
 */
function toHex(bytes) {
	let out = '';
	for (let i = 0; i < bytes.length; i++) out += bytes[i].toString(16).padStart(2, '0');
	return out;
}

/**
 * @param {Uint8Array} a
 * @param {Uint8Array} b
 * @returns {Uint8Array}
 */
function concatBytes(a, b) {
	const out = new Uint8Array(a.length + b.length);
	out.set(a, 0);
	out.set(b, a.length);
	return out;
}

/**
 * @typedef {object} TurnBudget
 * @property {number} startedAt        `Date.now()` at the top of the turn, before the guest sees the envelope.
 * @property {number} deadlineMs       `ATOMS_TURN_DEADLINE_MS` at the time the turn began.
 * @property {boolean} exhausted       Latched true once `turn_deadline_exceeded` has been produced once this turn.
 * @property {number} [exhaustedElapsedMs] Elapsed-at-exhaustion, cached so a later latched call reports it
 *                                          without another clock read (design §2.2).
 */

/**
 * @typedef {object} TurnCollector
 * @property {Promise<void>[]} promises  This turn's in-flight dispatch deliveries.
 * @property {number} dispatchCount      Jobs dispatched so far this turn, against ATOMS_MAX_DISPATCHES_PER_TURN.
 */

export class CallbackChannel {
	/**
	 * @param {object} opts
	 * @param {import('./config.js').AtomsConfig} opts.config
	 * @param {(level: string, fields: Record<string, unknown>) => void} opts.log
	 * @param {() => number} [opts.phpGenerationRef]
	 *   Snapshot of the residency's PHP generation counter, bumped whenever the
	 *   PHP instance is discarded. Read before and after the callback await so a
	 *   reply is never delivered to a dead guest (design §5.3).
	 */
	constructor({ config, log, phpGenerationRef }) {
		this.config = config;
		this.log = log;
		this.phpGenerationRef = phpGenerationRef ?? (() => 0);

		/** @type {Promise<CryptoKey>|null} memoized signing key import (design §6.1) */
		this.keyPromise = null;

		/** @type {{body: string, job: string}[]} dispatch() bodies buffered while a transaction is open */
		this.txBuffer = [];

		/** @type {TurnCollector|null} the current turn's collector, set by beginTurn() */
		this.collector = null;

		// Debug-endpoint observables (design §8), all monotonic per residency.
		this.callbackCalls = 0;
		this.dispatches = 0;
		this.dispatchFailures = 0;
	}

	/** @returns {'configured'|'unconfigured'|'misconfigured'} */
	get state() {
		return this.config.callbackState;
	}

	/** @returns {TurnCollector} a fresh per-turn delivery collector */
	newCollector() {
		return { promises: [], dispatchCount: 0 };
	}

	/**
	 * Open a callback window: a FRESH budget, and `collector` as the target for
	 * any `dispatch()` initiated while the window is open.
	 *
	 * Placement matters (design §4.1). There are exactly two kinds of window,
	 * and between them they cover every moment guest code can run:
	 *
	 *   - a **turn** window — opened after `ensureActive()` and before
	 *     `runTurn()` delivers the envelope, settled by `settlePostTurn()`;
	 *   - the **activation** window — opened before `php.run()` starts, so the
	 *     bootstrap, the migrations and `onActivation()` (customer code on the
	 *     legal ABI, which may call `app()`/`dispatch()`) all run inside one,
	 *     and settled by `activate()` before `ensureActive()` returns.
	 *
	 * The budget is always newly minted here and never reused: a budget that
	 * outlived its window would arrive at the next one already spent, and
	 * `exhausted` latches (§2.2), so the reuse is permanent rather than
	 * transient.
	 *
	 * @param {number} deadlineMs
	 * @param {TurnCollector} collector
	 * @returns {TurnBudget}
	 */
	beginTurn(deadlineMs, collector) {
		this.collector = collector;
		this.txBuffer = [];
		return { startedAt: Date.now(), deadlineMs, exhausted: false };
	}

	/**
	 * Close the callback window: drop the reference to the window's collector so
	 * a `dispatch.enqueue` (or a transaction-commit delivery) that somehow
	 * reaches the bridge BETWEEN windows finds no collector and is refused
	 * loudly by `enqueueJob()`/`onTransactionCommit()` rather than attaching a
	 * delivery to a stale, already-settled collector — or silently dropping it.
	 *
	 * The mirror of `beginTurn()` setting `this.collector`. `atom-do.js` calls
	 * it at the exact three seams it clears `turnBudget`: the `finally` of
	 * `runTurn()`, the `finally` of `activate()`, and `discardPhp()` — always
	 * AFTER the window's deliveries have been collected (they live on the
	 * collector object `settleDeliveries()` already holds by reference, so
	 * nulling this pointer never loses an in-flight delivery). Without it the
	 * `!this.collector` guards were structurally dead: `collector` was set once
	 * and never cleared, so the guards could not fire even if the A3 invariant
	 * broke.
	 */
	endWindow() {
		this.collector = null;
	}

	/**
	 * Await every delivery THIS turn's `dispatch()` calls started. Per-turn
	 * collectors are required (design §4.1): awaiting the wrong one would make
	 * turn N wait on turn N+1's deliveries, or worse, never settle.
	 *
	 * @param {TurnCollector|null} collector
	 */
	async settleTurn(collector) {
		if (!collector || collector.promises.length === 0) return;
		await Promise.allSettled(collector.promises);
	}

	// -------------------------------------------------------------- app.call

	/**
	 * Service one `app.call` park. Every path out of this method replies to
	 * `parked` (or explicitly drops the reply after logging, when the PHP
	 * instance was discarded mid-await — design §5.3): the park callback must
	 * ALWAYS be answered so the guest is never stranded.
	 *
	 * @param {import('./bridge.js').ParkedCall} parked
	 * @param {TurnBudget} budget
	 */
	async serviceAppCall(parked, budget) {
		const genAtStart = this.phpGenerationRef();
		/** @param {string} replyJson */
		const reply = (replyJson) => {
			if (this.phpGenerationRef() !== genAtStart) {
				this.log('error', { msg: 'atoms.callback.reply_after_discard', op: 'app.call' });
				return;
			}
			parked.reply(replyJson);
		};

		const channelFailure = this.checkChannel();
		if (channelFailure) {
			reply(channelFailure);
			return;
		}

		const bodyFailure = this.checkBody(parked.msg.body);
		if (bodyFailure) {
			reply(bodyFailure);
			return;
		}
		const bodyString = /** @type {string} */ (parked.msg.body);

		if (budget.exhausted) {
			// Latched: no clock read, no fetch. This is what stops a caught-and-
			// retried app() loop from hammering the monolith once the turn is
			// already out of time (design §2.2, checked by conformance 15b).
			reply(
				fail('turn_deadline_exceeded', 'the turn budget is already exhausted', {
					elapsed_ms: budget.exhaustedElapsedMs ?? budget.deadlineMs,
					budget_ms: budget.deadlineMs,
				})
			);
			return;
		}

		const elapsedAtStart = Date.now() - budget.startedAt;
		const remaining = budget.deadlineMs - elapsedAtStart;
		if (remaining <= 0) {
			this.latch(budget, elapsedAtStart);
			reply(
				fail('turn_deadline_exceeded', 'the turn budget is exhausted', {
					elapsed_ms: elapsedAtStart,
					budget_ms: budget.deadlineMs,
				})
			);
			return;
		}

		/** @type {{bodyBytes: Uint8Array, headers: Record<string, string>}} */
		let signed;
		try {
			signed = await this.signRequest(bodyString, 'methods');
		} catch (e) {
			reply(fail('callback_unsigned', `could not sign the callback request: ${errorMessage(e)}`));
			return;
		}

		// Recomputed HERE, after the (awaited) key import and signature, not
		// before: arming the abort with a `remaining` measured earlier would
		// hand the fetch a bound the turn no longer has. `importSigningKey()`
		// is memoized, so this only ever matters on the first callback of a
		// residency — which is exactly the one where the import cost is paid.
		const remainingAtFetch = budget.deadlineMs - (Date.now() - budget.startedAt);
		if (remainingAtFetch <= 0) {
			const elapsed = Date.now() - budget.startedAt;
			this.latch(budget, elapsed);
			reply(
				fail('turn_deadline_exceeded', 'the turn budget was exhausted before app() could be sent', {
					elapsed_ms: elapsed,
					budget_ms: budget.deadlineMs,
				})
			);
			return;
		}
		const perCallMs = Math.min(remainingAtFetch, this.config.callbackTimeoutMs);

		this.callbackCalls++;
		/** @type {Response} */
		let res;
		/** @type {string} */
		let text;
		try {
			res = await fetch(this.config.callbackUrl, {
				method: 'POST',
				body: signed.bodyBytes,
				headers: signed.headers,
				signal: AbortSignal.timeout(perCallMs),
			});
			// Refuse an over-cap response BEFORE reading it, when the monolith
			// declared its size. The post-read check below is still the one that
			// catches a chunked (or lying) response — the full fix is a
			// streaming bounded reader that stops at the cap mid-body, which is
			// deliberately deferred: it changes the "response text is relayed
			// verbatim" shape of this method and needs its own conformance
			// cover. Until then a chunked response is buffered whole before it
			// is refused.
			const declared = Number(res.headers.get('content-length') ?? '');
			if (Number.isFinite(declared) && declared > this.config.callbackMaxResponseBytes) {
				// Drain-and-discard so the connection can be reused; the body is
				// never handed to the guest.
				await res.body?.cancel().catch(() => {});
				reply(
					fail(
						'callback_too_large',
						`response declares ${declared} bytes, over ATOMS_CALLBACK_MAX_RESPONSE_BYTES ` +
							`(${this.config.callbackMaxResponseBytes})`
					)
				);
				return;
			}
			text = await res.text();
		} catch (e) {
			const elapsed = Date.now() - budget.startedAt;
			if (isTimeout(e)) {
				if (elapsed >= budget.deadlineMs) {
					this.latch(budget, elapsed);
					reply(
						fail('turn_deadline_exceeded', 'the turn budget was exhausted while awaiting app()', {
							elapsed_ms: elapsed,
							budget_ms: budget.deadlineMs,
						})
					);
				} else {
					reply(
						fail(
							'callback_timeout',
							`app() did not complete within ATOMS_CALLBACK_TIMEOUT_MS (${this.config.callbackTimeoutMs}ms)`,
							{ elapsed_ms: elapsed }
						)
					);
				}
				return;
			}
			reply(fail('callback_transport', `app() call failed: ${errorMessage(e)}`));
			return;
		}

		const responseBytes = encoder.encode(text).length;
		if (responseBytes > this.config.callbackMaxResponseBytes) {
			reply(
				fail(
					'callback_too_large',
					`response body is ${responseBytes} bytes, over ATOMS_CALLBACK_MAX_RESPONSE_BYTES ` +
						`(${this.config.callbackMaxResponseBytes})`
				)
			);
			return;
		}

		reply(ok({ status: res.status, body: text }));
	}

	// ------------------------------------------------------------ dispatch()

	/**
	 * Service one `dispatch.enqueue` sync message. Genuinely synchronous: it
	 * validates and either buffers the body (a transaction is open) or
	 * *initiates* the signed POST without awaiting it, handing the promise to
	 * this turn's collector. Never throws — bridge.js's sync door contract.
	 *
	 * @param {object} opts
	 * @param {unknown} opts.body
	 * @param {string} opts.job label only, never used to build the request (opaque-body invariant)
	 * @param {boolean} opts.inTransaction
	 * @returns {string} the JSON reply
	 */
	enqueueJob({ body, job, inTransaction }) {
		const channelFailure = this.checkChannel();
		if (channelFailure) return channelFailure;

		const bodyFailure = this.checkBody(body);
		if (bodyFailure) return bodyFailure;
		const bodyString = /** @type {string} */ (body);

		if (!this.collector) {
			// Off the happy path: guest code only runs inside a callback window
			// (`beginTurn()`), the activation window included, so a legitimate
			// `dispatch.enqueue` always has a collector. But the collector is now
			// cleared by `endWindow()` the moment each window closes, so this
			// branch is a GENUINE guard rather than dead code: a dispatch that
			// reaches the bridge between windows (a broken A3 invariant) finds
			// `collector === null` and is refused here — loudly — instead of
			// silently attaching a delivery to a settled window that nothing
			// would await. Kept as a refusal rather than a throw: the sync door
			// must not throw, and the guest gets a typed failure it can see
			// rather than a job that quietly never left.
			this.log('error', {
				msg: 'atoms.callback.dispatch_outside_window',
				job: this.logLabel(job),
				error: 'dispatch.enqueue reached the bridge with no callback window open',
			});
			return fail('bad_host_message', 'dispatch.enqueue received outside a turn');
		}
		if (this.collector.dispatchCount >= this.config.maxDispatchesPerTurn) {
			return fail(
				'dispatch_limit',
				`this turn already dispatched ATOMS_MAX_DISPATCHES_PER_TURN (${this.config.maxDispatchesPerTurn}) jobs`
			);
		}
		this.collector.dispatchCount++;

		if (inTransaction) {
			this.txBuffer.push({ body: bodyString, job });
			return ok({ buffered: true });
		}

		this.startDelivery(bodyString, job, this.collector);
		return ok({ buffered: false });
	}

	/** Move buffered dispatches to in-flight. Called by TransactionMachine on commit (design §3.4). */
	onTransactionCommit() {
		const buffered = this.txBuffer;
		this.txBuffer = [];
		if (buffered.length === 0) return;

		const collector = this.collector;
		if (!collector) {
			// A genuine guard for the same reason as `enqueueJob`'s: a
			// transaction can only be open while guest code is running, which is
			// always inside a callback window — but `endWindow()` now nulls the
			// collector the moment each window closes, so a commit that somehow
			// lands between windows reaches this branch instead of finding a
			// stale collector. There is nowhere to attach these deliveries that
			// anything would await — starting them anyway would orphan them
			// across the DO event (design §5) — so they are dropped LOUDLY, one
			// line per job, never silently.
			for (const { job } of buffered) {
				this.dispatchFailures++;
				this.logDeliveryFailure(job, 'no_callback_window', null, 0, null);
			}
			return;
		}

		for (const { body, job } of buffered) {
			this.startDelivery(body, job, collector);
		}
	}

	/**
	 * Drop buffered dispatches. Called by TransactionMachine on rollback/abandon
	 * (design §3.4). The per-turn dispatch cap is refunded for them: a job the
	 * runtime itself decided never happened must not consume the budget of the
	 * turn that retries it inside the same residency.
	 */
	onTransactionRollback() {
		const dropped = this.txBuffer.length;
		this.txBuffer = [];
		if (dropped > 0 && this.collector) {
			this.collector.dispatchCount = Math.max(0, this.collector.dispatchCount - dropped);
		}
	}

	/**
	 * Fire the signed POST without awaiting it here; register the promise on
	 * the turn's collector so `settleTurn()` waits for it before the turn's
	 * response goes out (design §4.1). `deliverJob()` never rejects: a
	 * delivery failure is logged and dropped, per §4.2.
	 *
	 * @param {string} bodyString
	 * @param {string} job
	 * @param {TurnCollector} collector
	 */
	startDelivery(bodyString, job, collector) {
		this.dispatches++;
		collector.promises.push(this.deliverJob(bodyString, job));
	}

	/**
	 * @param {string} bodyString
	 * @param {string} job
	 * @returns {Promise<void>}
	 */
	async deliverJob(bodyString, job) {
		const startedAt = Date.now();
		/** @type {{bodyBytes: Uint8Array, headers: Record<string, string>}} */
		let signed;
		try {
			signed = await this.signRequest(bodyString, 'job');
		} catch (e) {
			this.dispatchFailures++;
			this.logDeliveryFailure(job, 'sign_failed', null, Date.now() - startedAt, e);
			return;
		}

		try {
			const res = await fetch(this.config.callbackUrl, {
				method: 'POST',
				body: signed.bodyBytes,
				headers: signed.headers,
				signal: AbortSignal.timeout(this.config.callbackTimeoutMs),
			});
			// Drain the body so the connection can be reused; the content is
			// fire-and-forget and irrelevant to the caller.
			await res.text().catch(() => {});
			if (res.status < 200 || res.status >= 300) {
				this.dispatchFailures++;
				this.logDeliveryFailure(job, `http_${res.status}`, res.status, Date.now() - startedAt, null);
			}
		} catch (e) {
			this.dispatchFailures++;
			const reason = isTimeout(e) ? 'timeout' : 'transport';
			this.logDeliveryFailure(job, reason, null, Date.now() - startedAt, e);
		}
	}

	/**
	 * @param {string} job
	 * @param {string} reason
	 * @param {number|null} status
	 * @param {number} elapsedMs
	 * @param {unknown} e
	 */
	logDeliveryFailure(job, reason, status, elapsedMs, e) {
		this.log('error', {
			msg: 'atoms.callback.delivery_failed',
			job: this.logLabel(job),
			reason,
			status,
			elapsed_ms: elapsedMs,
			...(e ? { error: errorMessage(e) } : {}),
		});
	}

	/**
	 * The job label is guest-supplied (`{"op":"dispatch.enqueue","job":...}`)
	 * and reaches the log verbatim, so it obeys the same `ATOMS_LOG_MAX_FIELD_BYTES`
	 * cap every other logged field does — a customer must not be able to write a
	 * megabyte class name into a log line.
	 *
	 * @param {string} job
	 * @returns {string}
	 */
	logLabel(job) {
		const max = this.config.logMaxFieldBytes;
		const bytes = encoder.encode(job);
		if (bytes.length <= max) return job;
		return new TextDecoder().decode(bytes.subarray(0, max)) + '…';
	}

	// -------------------------------------------------------------- helpers

	/**
	 * Once `turn_deadline_exceeded` has been produced, latch the budget for
	 * the rest of the turn and cache the elapsed time it was latched at, so a
	 * later latched call can report without another clock read (design §2.2).
	 *
	 * @param {TurnBudget} budget
	 * @param {number} elapsedMs
	 */
	latch(budget, elapsedMs) {
		budget.exhausted = true;
		budget.exhaustedElapsedMs = elapsedMs;
	}

	/** @returns {string|null} a `callback_not_configured`/`callback_unsigned` reply, or null when usable */
	checkChannel() {
		if (this.state === 'unconfigured') {
			return fail(
				'callback_not_configured',
				'ATOMS_CALLBACK_URL is not set; app()/dispatch() have no callback channel configured'
			);
		}
		if (this.state === 'misconfigured') {
			return fail('callback_unsigned', this.config.callbackConfigError ?? 'the callback channel is misconfigured');
		}
		return null;
	}

	/**
	 * @param {unknown} body
	 * @returns {string|null} a `callback_body_invalid`/`callback_too_large` reply, or null when usable
	 */
	checkBody(body) {
		if (typeof body !== 'string' || body === '') {
			return fail('callback_body_invalid', 'the request requires a non-empty string "body"');
		}
		if (LONE_SURROGATE_RE.test(body)) {
			return fail(
				'callback_body_invalid',
				'body contains an unpaired UTF-16 surrogate, which cannot be encoded as valid UTF-8'
			);
		}
		const byteLength = encoder.encode(body).length;
		if (byteLength > this.config.callbackMaxRequestBytes) {
			return fail(
				'callback_too_large',
				`request body is ${byteLength} bytes, over ATOMS_CALLBACK_MAX_REQUEST_BYTES ` +
					`(${this.config.callbackMaxRequestBytes})`
			);
		}
		return null;
	}

	/**
	 * Import (and memoize) the Ed25519 signing key. `extractable: false`: the
	 * Worker never needs to export it, and a customer Atom that reads
	 * arbitrary guest memory still cannot obtain it — the key never enters
	 * wasm at all (design §6.1).
	 *
	 * @returns {Promise<CryptoKey>}
	 */
	importSigningKey() {
		if (!this.keyPromise) {
			this.keyPromise = (async () => {
				const seed = base64ToBytes(this.config.callbackSigningKey);
				if (!seed || seed.length !== 32) {
					throw new AtomsError('internal', 'ATOMS_CALLBACK_SIGNING_KEY must decode to exactly 32 bytes');
				}
				const pkcs8 = new Uint8Array(48);
				pkcs8.set(PKCS8_PREFIX, 0);
				pkcs8.set(seed, 16);
				return crypto.subtle.importKey('pkcs8', pkcs8, 'Ed25519', false, ['sign']);
			})();
		}
		return this.keyPromise;
	}

	/**
	 * Build the signed request for one callback body. `bodyBytes` is computed
	 * once and used for both signing and sending — "signed ≡ sent" is true by
	 * construction (design §1.1, §6.2).
	 *
	 * @param {string} bodyString
	 * @param {'methods'|'job'} kind
	 * @returns {Promise<{bodyBytes: Uint8Array, headers: Record<string, string>}>}
	 */
	async signRequest(bodyString, kind) {
		const bodyBytes = encoder.encode(bodyString);
		const ts = String(Math.floor(Date.now() / 1000));
		const nonce = toHex(crypto.getRandomValues(new Uint8Array(16)));
		const prefixBytes = encoder.encode(`v1\n${ts}\n${nonce}\n`);
		const message = concatBytes(prefixBytes, bodyBytes);

		const key = await this.importSigningKey();
		const sig = new Uint8Array(await crypto.subtle.sign('Ed25519', key, message));

		return {
			bodyBytes,
			headers: {
				'content-type': 'application/json',
				'x-atoms-signature': bytesToBase64(sig),
				'x-atoms-timestamp': ts,
				'x-atoms-nonce': nonce,
				'x-atoms-kind': kind,
			},
		};
	}
}

/**
 * `AbortSignal.timeout()` aborts with a DOMException named "TimeoutError"
 * (design doc §0.1 M3, measured against the pinned wrangler/workerd).
 *
 * @param {unknown} e
 * @returns {boolean}
 */
function isTimeout(e) {
	return !!e && typeof e === 'object' && /** @type {any} */ (e).name === 'TimeoutError';
}

/**
 * @param {unknown} e
 * @returns {string}
 */
function errorMessage(e) {
	if (e instanceof Error) return e.message;
	return String(e);
}
