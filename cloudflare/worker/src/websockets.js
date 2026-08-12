/**
 * The WebSocket seam: the host side of `ws.send` / `ws.close` / `ws.broadcast`
 * (sync ops dispatched from `Bridge.handleSync`), the `{v,id,ch}` attachment
 * format, and the connId -> socket memo that makes a
 * post-wake send resolve in one call instead of a scan.
 *
 * Nothing here parses or re-encodes a broadcast frame — it is a string built
 * entirely by the PHP guest (`CfAtomContext::broadcast()`) and fanned out
 * opaquely, which is what keeps a wide integer inside it exact
 * (mvp-spec.md's int64 rule).
 */
import { base64ToBytes } from './config.js';
import { AtomsError, normalizeError } from './errors.js';

/**
 * Wire version of the `{v,id,ch}` attachment written once at accept. Not
 * env-tunable: a protocol constant, not a capacity value, exactly
 * like `BOOT_PROTOCOL` in atom-do.js.
 */
export const WS_ATTACHMENT_VERSION = 1;

/**
 * A stand-in connection id, exactly as long as the `crypto.randomUUID()` the
 * accept path will mint (36 characters), so the edge can size an attachment
 * and a connection tag before the real id exists (`index.js`'s `wsUpgrade()`).
 * Not a capacity value: it is the platform's UUID string length, the same
 * category of protocol constant as `WS_ATTACHMENT_VERSION`.
 */
export const WS_CONN_ID_PLACEHOLDER = '00000000-0000-0000-0000-000000000000';

const encoder = new TextEncoder();

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

/**
 * @param {unknown} e
 * @returns {string}
 */
function errorMessage(e) {
	return e instanceof Error ? e.message : String(e);
}

/**
 * Build the `{v,id,ch}` attachment for a newly accepted connection.
 * `channels` must already be validated, de-duplicated, and in accepted
 * order — this function does no validation of its own.
 *
 * @param {string} connId
 * @param {string[]} channels
 * @returns {{v: number, id: string, ch: string[]}}
 */
export function buildAttachment(connId, channels) {
	return { v: WS_ATTACHMENT_VERSION, id: connId, ch: channels };
}

/**
 * Serialized size of an attachment, in bytes. A conservative proxy for what
 * `serializeAttachment()` actually stores (structured-clone, not JSON) — good
 * enough because the attachment is a flat JSON-safe object of strings and the
 * configured cap (default 512) sits far below both the measured local
 * limit (16384 bytes) and the smaller number production may enforce, so a
 * few bytes of proxy slop can never be the difference between accepted and
 * refused.
 *
 * @param {unknown} attachment
 * @returns {number}
 */
export function attachmentByteLength(attachment) {
	return encoder.encode(JSON.stringify(attachment)).length;
}

/**
 * Read and validate a socket's attachment. Returns `null` when it is missing,
 * unparseable, or shaped wrong — the caller drops the socket rather than
 * guessing: a socket accepted by an older/newer deployment is
 * a cross-version wire format, not an in-process struct.
 *
 * @param {any} ws
 * @returns {{v: number, id: string, ch: string[]}|null}
 */
export function readAttachment(ws) {
	/** @type {any} */
	let att;
	try {
		att = ws.deserializeAttachment();
	} catch {
		return null;
	}
	if (typeof att !== 'object' || att === null) return null;
	if (typeof att.v !== 'number') return null;
	if (typeof att.id !== 'string' || att.id === '') return null;
	if (!Array.isArray(att.ch) || !att.ch.every((c) => typeof c === 'string')) return null;
	return { v: att.v, id: att.id, ch: att.ch };
}

/**
 * @param {unknown} connId
 * @returns {string}
 */
function requireConnId(connId) {
	if (typeof connId !== 'string' || connId === '') {
		throw new AtomsError('invalid_request', 'ws op requires a non-empty "conn" string');
	}
	return connId;
}

/**
 * Decode the outbound payload of a `ws.send` request into what
 * `WebSocket.send()` wants: a string for a text frame (the `payload` key), or
 * an `ArrayBuffer` for a binary frame (the `payload_b64` key) — the opcode
 * rule the guest's `CfConnection::send()` already implements.
 * Exactly one of the two keys must be present.
 *
 * @param {any} msg
 * @param {number} maxBytes
 * @returns {{data: string|ArrayBuffer, bytes: number, binary: boolean}}
 */
function decodeOutboundPayload(msg, maxBytes) {
	const hasText = typeof msg.payload === 'string';
	const hasBinary = typeof msg.payload_b64 === 'string';

	if (hasText === hasBinary) {
		throw new AtomsError('invalid_request', 'ws.send requires exactly one of "payload" or "payload_b64"');
	}

	if (hasText) {
		const bytes = encoder.encode(msg.payload).length;
		if (bytes > maxBytes) {
			throw new AtomsError(
				'invalid_request',
				`ws.send payload is ${bytes} bytes, over ATOMS_WS_MAX_SEND_BYTES (${maxBytes})`
			);
		}
		return { data: msg.payload, bytes, binary: false };
	}

	const decoded = base64ToBytes(msg.payload_b64);
	if (decoded === null) {
		throw new AtomsError('invalid_request', 'ws.send "payload_b64" is not valid base64');
	}
	if (decoded.length > maxBytes) {
		throw new AtomsError(
			'invalid_request',
			`ws.send payload is ${decoded.length} bytes, over ATOMS_WS_MAX_SEND_BYTES (${maxBytes})`
		);
	}
	return { data: decoded.buffer.slice(decoded.byteOffset, decoded.byteOffset + decoded.length), bytes: decoded.length, binary: true };
}

/**
 * One instance per DO residency, alongside `Bridge`/`CallbackChannel`/
 * `TransactionMachine` in `atom-do.js`. Owns the residency-local connId ->
 * socket memo (never persisted, never written to an attachment)
 * and answers the three ws.* sync ops.
 */
export class WebSocketHost {
	/**
	 * @param {object} opts
	 * @param {any} opts.ctx DurableObjectState
	 * @param {import('./config.js').AtomsConfig} opts.config
	 * @param {(level: string, fields: Record<string, unknown>) => void} opts.log
	 */
	constructor({ ctx, config, log }) {
		this.ctx = ctx;
		this.config = config;
		this.log = log;

		/** @type {Map<string, any>} residency-local, NEVER persisted */
		this.memo = new Map();

		// Debug-endpoint observables, monotonic per residency.
		this.sendsThisResidency = 0;
		this.broadcastsThisResidency = 0;
	}

	/**
	 * Record a socket the platform just handed us directly — at accept, or on
	 * any wake event (`webSocketMessage`/`webSocketClose` hand the object
	 * itself).
	 *
	 * @param {string} connId
	 * @param {any} ws
	 */
	noteSocket(connId, ws) {
		this.memo.set(connId, ws);
	}

	/** @param {string} connId */
	forgetSocket(connId) {
		this.memo.delete(connId);
	}

	/** Discard the whole memo. Called from `discardPhp()`: it is residency-local. */
	clearMemo() {
		this.memo.clear();
	}

	/**
	 * connId -> socket. O(1) through the memo; falls back to the platform's
	 * own tag index, which needs no reconstruction after a wake.
	 *
	 * @param {string} connId
	 * @returns {any|null}
	 */
	socketFor(connId) {
		const memo = this.memo.get(connId);
		if (memo) return memo;
		const found = this.ctx.getWebSockets(this.config.wsConnTagPrefix + connId);
		if (found.length !== 1) return null; // 0 = gone; >1 is impossible (UUID)
		this.memo.set(connId, found[0]);
		return found[0];
	}

	// -------------------------------------------------------------- sync ops

	/**
	 * `{"op":"ws.send","conn":string,"payload"?:string,"payload_b64"?:string}`
	 *
	 * @param {any} msg
	 * @returns {string}
	 */
	opSend(msg) {
		const connId = requireConnId(msg.conn);
		const { data, bytes, binary } = decodeOutboundPayload(msg, this.config.wsMaxSendBytes);

		const ws = this.socketFor(connId);
		if (!ws) return fail('ws_conn_gone', `connection ${JSON.stringify(connId)} is gone`);

		this.sendsThisResidency++;
		try {
			ws.send(data);
		} catch (e) {
			// send() after close() throws. The id resolved a moment ago and
			// is gone now — same observable the guest sees either way.
			this.forgetSocket(connId);
			return fail('ws_conn_gone', `connection ${JSON.stringify(connId)} closed mid-send: ${errorMessage(e)}`);
		}
		return ok({ bytes, binary });
	}

	/**
	 * `{"op":"ws.close","conn":string,"code"?:number,"reason"?:string}` — a
	 * gone connection is a silent success: closing an already-gone
	 * thing got the outcome the caller wanted.
	 *
	 * @param {any} msg
	 * @returns {string}
	 */
	opClose(msg) {
		const connId = requireConnId(msg.conn);
		const ws = this.socketFor(connId);
		if (!ws) return ok({ already_gone: true });

		const code = Number.isInteger(msg.code) ? msg.code : 1000;
		const reason = typeof msg.reason === 'string' ? msg.reason : '';
		try {
			ws.close(code, reason);
		} catch (e) {
			// A second close() does not throw at the platform level, but
			// nothing guarantees every path here is a first close — best effort,
			// logged, never surfaced as a guest-visible failure.
			this.log('warning', { msg: 'atoms.ws.close_failed', conn: connId, error: errorMessage(e) });
		}
		this.forgetSocket(connId);
		return ok({});
	}

	/**
	 * `{"op":"ws.broadcast","channel":string,"frame":string}` — `frame` is the
	 * COMPLETE wire text, built and `json_encode()`d entirely by the guest.
	 * This never parses or re-encodes it (mvp-spec.md's int64 rule): it is a
	 * string in, the same string out, to every socket on the channel.
	 *
	 * @param {any} msg
	 * @returns {string}
	 */
	opBroadcast(msg) {
		const channel = msg.channel;
		if (typeof channel !== 'string' || channel === '') {
			throw new AtomsError('invalid_request', 'ws.broadcast requires a non-empty "channel" string');
		}
		const frame = msg.frame;
		if (typeof frame !== 'string' || frame === '') {
			throw new AtomsError('invalid_request', 'ws.broadcast requires a non-empty "frame" string');
		}
		const frameBytes = encoder.encode(frame).length;
		if (frameBytes > this.config.wsMaxSendBytes) {
			throw new AtomsError(
				'invalid_request',
				`ws.broadcast frame is ${frameBytes} bytes, over ATOMS_WS_MAX_SEND_BYTES (${this.config.wsMaxSendBytes})`
			);
		}

		const sockets = this.ctx.getWebSockets(this.config.wsChannelTagPrefix + channel);
		if (sockets.length > this.config.wsMaxBroadcastSockets) {
			throw new AtomsError(
				'ws_fanout_limit',
				`broadcast to channel ${JSON.stringify(channel)} would reach ${sockets.length} sockets, ` +
					`over ATOMS_WS_MAX_BROADCAST_SOCKETS (${this.config.wsMaxBroadcastSockets})`
			);
		}

		this.broadcastsThisResidency++;
		let delivered = 0;
		let failed = 0;
		for (const ws of sockets) {
			try {
				ws.send(frame);
				delivered++;
			} catch {
				failed++;
			}
		}
		this.log('debug', { msg: 'atoms.ws.broadcast', channel, sockets: sockets.length, delivered, failed });
		return ok({ delivered, failed });
	}

	/**
	 * Dispatch one ws.* sync op. Mirrors `Bridge.handleSync`'s contract:
	 * always returns a JSON reply string, never throws out of the sync door.
	 *
	 * @param {string} op
	 * @param {any} msg
	 * @returns {string}
	 */
	handleSync(op, msg) {
		try {
			switch (op) {
				case 'ws.send':
					return this.opSend(msg);
				case 'ws.close':
					return this.opClose(msg);
				case 'ws.broadcast':
					return this.opBroadcast(msg);
				default:
					return fail('bad_host_message', `unknown ws sync op "${op}"`);
			}
		} catch (e) {
			const n = normalizeError(e);
			return fail(n.code, n.message, n.detail);
		}
	}

	// ------------------------------------------------------------- debug info

	/**
	 * The `"ws"` block of `AtomDurableObject.info()`. Reads only
	 * `ctx.getWebSockets()`/`getTags()`/attachments — never activates PHP, so
	 * it stays usable on an evicted residency.
	 *
	 * @param {number} connectsThisResidency
	 * @param {number} turnsThisResidency ws.* turns only (connect+message+close)
	 * @returns {Record<string, unknown>}
	 */
	debugBlock(connectsThisResidency, turnsThisResidency) {
		const sockets = this.ctx.getWebSockets();
		/** @type {Record<string, number>} */
		const byChannel = {};
		/** @type {Record<string, unknown>[]} */
		const connections = [];
		const limit = this.config.wsDebugMaxConnections;

		for (const ws of sockets) {
			const att = readAttachment(ws);
			const channels = att?.ch ?? [];
			for (const ch of channels) byChannel[ch] = (byChannel[ch] ?? 0) + 1;

			if (connections.length < limit) {
				let tags = [];
				try {
					tags = this.ctx.getTags(ws);
				} catch {
					/* best effort */
				}
				connections.push({ id: att?.id ?? null, channels, tags });
			}
		}

		return {
			sockets: sockets.length,
			by_channel: byChannel,
			connections,
			truncated: sockets.length > limit,
			connects_this_residency: connectsThisResidency,
			turns_this_residency: turnsThisResidency,
			sends_this_residency: this.sendsThisResidency,
			broadcasts_this_residency: this.broadcastsThisResidency,
			attachment_version: WS_ATTACHMENT_VERSION,
		};
	}
}
