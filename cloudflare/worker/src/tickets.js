/**
 * WebSocket connection tickets (spec §Routing and auth).
 *
 * A ticket is the browser's credential for `GET /ws/:type/:id`: browsers
 * cannot set an `Authorization` header on `new WebSocket(url)`, so the
 * application's server mints one at `POST /tickets/:type/:id` (behind the same
 * credential gate as every other route) and the browser presents it as
 * `?ticket=`. Short-TTL, scoped to exactly one atom, and a carrier for
 * server-asserted claims that merge over the browser's own query params.
 *
 * Wire form — one form, always signed, in every auth posture:
 *
 *   v1.<base64url(payload)>.<base64url(HMAC-SHA256 sig)>
 *
 * Payload: {"t": type, "i": id, "exp": epoch-ms, "jti": <32 hex>, "claims": {...}}.
 * The signature covers `"v1\n" + <payload base64url segment>` — the same
 * versioned, newline-delimited signing-string idiom as the callback channel's
 * `signRequest()`. The key is derived from `ATOMS_SHARED_SECRET` (HKDF-SHA256,
 * empty salt, info `atoms/ws-ticket/v1` — a protocol constant, the same
 * category as `WS_ATTACHMENT_VERSION`; see `derive.js`), so there is no second
 * secret and rotating the shared secret invalidates every outstanding ticket
 * at once.
 *
 * Tickets take **no** rotation overlap: verification uses the current secret
 * only, never `ATOMS_SHARED_SECRET_PREVIOUS`. They carry a seconds-scale TTL
 * and are re-minted through the application, so a flip costs at most one
 * re-mint per connection.
 *
 * Everything here is stateless, and so is the whole ticket contract: a
 * ticket is deliberately reusable until it expires, and the seconds-scale
 * TTL is the entire defense against a leaked URL (spec §Routing and auth).
 * The `jti` is a per-mint identifier for logs and any future revocation
 * contract; nothing consumes it.
 */

import { deriveTicketKey } from './derive.js';
import { AtomsError } from './errors.js';

/** Signed-message prefix (`"v1\n" + payloadB64`). */
const TICKET_SIGNING_PREFIX = 'v1\n';

const encoder = new TextEncoder();

/**
 * @param {Uint8Array} bytes
 * @returns {string}
 */
function base64UrlEncode(bytes) {
	let binary = '';
	for (let i = 0; i < bytes.length; i++) binary += String.fromCharCode(bytes[i]);
	return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

/**
 * Non-canonical rejecting: a base64(url) group's unused low-order "wasted"
 * bits (present whenever the encoded byte count is not a multiple of 3) are
 * conventionally zero and simply discarded on decode — meaning two distinct
 * strings can decode to the identical byte string if they differ only in
 * those wasted bits. Left unchecked, that turns `verifyTicket`'s signature
 * check into a malleability hole: flipping the ticket's last character can
 * decode to the same signature bytes and still verify, so a "tampered"
 * ticket is silently accepted. Requiring the round trip to reproduce the
 * exact input closes it for both the signature and payload segments.
 *
 * @param {string} s
 * @returns {Uint8Array|null} null on anything that is not canonical base64url
 */
function base64UrlDecode(s) {
	if (!/^[A-Za-z0-9_-]*$/.test(s)) return null;
	const b64 = s.replace(/-/g, '+').replace(/_/g, '/');
	try {
		const binary = atob(b64 + '='.repeat((4 - (b64.length % 4)) % 4));
		const bytes = new Uint8Array(binary.length);
		for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
		if (base64UrlEncode(bytes) !== s) return null;
		return bytes;
	} catch {
		return null;
	}
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
 * The ticket key for the CURRENT shared secret. `derive.js` memoizes the
 * derivation per secret for the life of the isolate.
 *
 * @param {import('./config.js').AtomsConfig} config with `sharedSecretState === 'configured'`
 * @returns {Promise<CryptoKey>} non-extractable HMAC-SHA256 key
 */
function ticketKey(config) {
	const secret = config.sharedSecretBytes;
	if (secret === null) {
		throw new AtomsError('internal', 'ATOMS_SHARED_SECRET is not configured');
	}
	return deriveTicketKey(secret);
}

/**
 * Validate a mint request's claims: a flat own-property string→string map,
 * within the configured caps, with the reserved keys refused. Returns a
 * null-prototype copy so `?__proto__=` games are unrepresentable downstream
 * (same reasoning as `parseWsParams`).
 *
 * @param {unknown} raw the request body's `claims` member (absent = {})
 * @param {import('./config.js').AtomsConfig} config
 * @returns {Record<string, string>}
 * @throws {AtomsError} `invalid_request`
 */
export function validateClaims(raw, config) {
	/** @type {Record<string, string>} */
	const claims = Object.create(null);
	if (raw === undefined || raw === null) return claims;
	if (typeof raw !== 'object' || Array.isArray(raw)) {
		throw new AtomsError('invalid_request', '"claims" must be a JSON object of string values');
	}

	let count = 0;
	let totalBytes = 0;
	for (const key of Object.keys(raw)) {
		const value = /** @type {Record<string, unknown>} */ (raw)[key];
		if (typeof value !== 'string') {
			throw new AtomsError('invalid_request', `claim ${JSON.stringify(key)} must be a string`);
		}
		// `ticket` never reaches onConnect (it is the reserved credential key),
		// and `channels` as a claim would desync the delivered params from the
		// actual channel membership, which is fixed from the query string.
		if (key === 'ticket' || key === 'channels') {
			throw new AtomsError('invalid_request', `claim key ${JSON.stringify(key)} is reserved`);
		}
		count++;
		totalBytes += encoder.encode(key).length + encoder.encode(value).length;
		claims[key] = value;
	}

	if (count > config.wsTicketMaxClaims) {
		throw new AtomsError(
			'invalid_request',
			`the mint request has ${count} claims, over ATOMS_WS_TICKET_MAX_CLAIMS (${config.wsTicketMaxClaims})`
		);
	}
	if (totalBytes > config.wsTicketMaxClaimBytes) {
		throw new AtomsError(
			'invalid_request',
			`the mint request's claims total ${totalBytes} bytes, over ATOMS_WS_TICKET_MAX_CLAIM_BYTES ` +
				`(${config.wsTicketMaxClaimBytes})`
		);
	}

	return claims;
}

/**
 * Mint a ticket for one atom. Always signed: the Worker always holds a shared
 * secret, so local dev and production run the same code path.
 *
 * @param {import('./config.js').AtomsConfig} config
 * @param {string} type
 * @param {string} id
 * @param {Record<string, string>} claims already validated
 * @param {number} nowMs
 * @returns {Promise<{ticket: string, expiresAt: number, jti: string}>}
 */
export async function mintTicket(config, type, id, claims, nowMs) {
	const expiresAt = nowMs + config.wsTicketTtlMs;
	const jti = toHex(crypto.getRandomValues(new Uint8Array(16)));
	const payloadB64 = base64UrlEncode(
		encoder.encode(JSON.stringify({ t: type, i: id, exp: expiresAt, jti, claims }))
	);

	const key = await ticketKey(config);
	const sig = new Uint8Array(
		await crypto.subtle.sign('HMAC', key, encoder.encode(TICKET_SIGNING_PREFIX + payloadB64))
	);
	return { ticket: `v1.${payloadB64}.${base64UrlEncode(sig)}`, expiresAt, jti };
}

/**
 * Verify a presented ticket against the atom it claims to be for. Stateless:
 * everything here runs at the edge, before any DO is addressed, so a forged,
 * expired or mis-scoped ticket never costs an activation.
 *
 * Order: length cap; version/format; signature; payload shape; scope; expiry.
 * The signature is checked under the CURRENT shared secret only — a ticket
 * signed under `ATOMS_SHARED_SECRET_PREVIOUS` is `ticket_invalid` — so a
 * rotation costs at most one re-mint per connection and the previous secret
 * widens nothing here.
 *
 * @param {import('./config.js').AtomsConfig} config
 * @param {string} raw the `?ticket=` value
 * @param {string} type decoded path segment
 * @param {string} id decoded path segment
 * @param {number} nowMs
 * @returns {Promise<{claims: Record<string, string>, jti: string, exp: number}>}
 * @throws {AtomsError} `ticket_invalid` | `ticket_expired`
 */
export async function verifyTicket(config, raw, type, id, nowMs) {
	if (raw.length > config.wsTicketMaxBytes) {
		throw new AtomsError(
			'ticket_invalid',
			`the ticket is ${raw.length} characters, over ATOMS_WS_TICKET_MAX_BYTES (${config.wsTicketMaxBytes})`
		);
	}

	const segments = raw.split('.');
	if (segments[0] !== 'v1' || segments.length !== 3) {
		throw new AtomsError('ticket_invalid', 'the ticket is not a v1 connection ticket');
	}
	const payloadB64 = segments[1];

	const sig = base64UrlDecode(segments[2]);
	const key = await ticketKey(config);
	const ok =
		sig !== null &&
		(await crypto.subtle.verify('HMAC', key, sig, encoder.encode(TICKET_SIGNING_PREFIX + payloadB64)));
	if (!ok) {
		throw new AtomsError('ticket_invalid', 'the ticket signature does not verify');
	}

	const payloadBytes = base64UrlDecode(payloadB64);
	/** @type {any} */
	let payload;
	try {
		payload = payloadBytes === null ? null : JSON.parse(new TextDecoder().decode(payloadBytes));
	} catch {
		payload = null;
	}
	if (
		payload === null ||
		typeof payload !== 'object' ||
		Array.isArray(payload) ||
		typeof payload.t !== 'string' ||
		typeof payload.i !== 'string' ||
		typeof payload.exp !== 'number' ||
		!Number.isFinite(payload.exp) ||
		typeof payload.jti !== 'string' ||
		payload.jti === ''
	) {
		throw new AtomsError('ticket_invalid', 'the ticket payload is malformed');
	}

	if (payload.t !== type || payload.i !== id) {
		throw new AtomsError(
			'ticket_invalid',
			`the ticket is scoped to ${JSON.stringify(payload.t)}/${JSON.stringify(payload.i)}, ` +
				`not to this atom`
		);
	}

	if (nowMs > payload.exp + config.wsTicketSkewMs) {
		throw new AtomsError('ticket_expired', 'the ticket has expired; mint a fresh one');
	}

	// Claims: a flat string map, copied onto a null prototype so a forged
	// payload's `__proto__` key (legal JSON, and JSON.parse writes it as an
	// own property) cannot pollute the merged params object downstream.
	/** @type {Record<string, string>} */
	const claims = Object.create(null);
	const rawClaims = payload.claims;
	if (rawClaims !== undefined && rawClaims !== null) {
		if (typeof rawClaims !== 'object' || Array.isArray(rawClaims)) {
			throw new AtomsError('ticket_invalid', 'the ticket payload is malformed');
		}
		for (const key of Object.keys(rawClaims)) {
			const value = rawClaims[key];
			if (typeof value !== 'string' || key === 'ticket' || key === 'channels') {
				throw new AtomsError('ticket_invalid', 'the ticket payload is malformed');
			}
			claims[key] = value;
		}
	}

	return { claims, jti: payload.jti, exp: payload.exp };
}
