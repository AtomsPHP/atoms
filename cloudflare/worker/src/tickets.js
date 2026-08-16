/**
 * WebSocket connection tickets (spec §Routing and auth, docs/ws-ticket-protocol.md).
 *
 * A ticket is the browser's credential for `GET /ws/:type/:id`: browsers
 * cannot set an `Authorization` header on `new WebSocket(url)`, so the
 * application issues one and the browser presents it as `?ticket=`.
 * Short-lived, scoped to exactly one atom, and a carrier for server-asserted
 * claims that merge over the browser's own query params.
 *
 * **This Worker only verifies.** Issuance is the application's, in process,
 * with no HTTP: it already holds `ATOMS_SHARED_SECRET` and derives the same
 * signing key, so a mint endpoint here was a round trip to compute something
 * the caller could compute itself. `Atoms\Client\Tickets\TicketIssuer` is the
 * reference issuer; the format below is the contract between the two.
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
 * secret. Note the signature covers the payload segment exactly as presented:
 * nothing here re-serializes the JSON, so an issuer's byte-level choices are
 * its own determinism problem, never a verification input.
 *
 * Tickets take the same rotation overlap as the bearer: the signature is
 * verified under the current secret, then under `ATOMS_SHARED_SECRET_PREVIOUS`
 * while one is configured. Try-both, never a key selector — the previous
 * secret is an operator-provisioned fallback tried unconditionally, never
 * chosen by anything in the ticket. A verifier accepts both, an issuer emits
 * only the current value, which is what keeps a rotation zero-downtime now
 * that the issuers are application instances rolling out on their own
 * schedule: mid-rollout, an instance that still holds the old secret signs
 * with it, and re-issuing would not help it. Deleting the previous secret
 * still invalidates every outstanding ticket signed under it, at once.
 *
 * Everything here is stateless, and so is the whole ticket contract: a
 * ticket is deliberately reusable until it expires, and the short lifetime
 * is the entire defense against a leaked URL (spec §Routing and auth).
 * The `jti` is a per-issue identifier for logs and any future revocation
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
 * The keys a presented ticket may be verified under: the current secret's,
 * followed by the previous secret's while a rotation overlap is configured.
 * `derive.js` memoizes each derivation per secret for the life of the isolate,
 * and keeps a slot per secret precisely so an overlap costs no re-derivation.
 *
 * @param {import('./config.js').AtomsConfig} config with `sharedSecretState === 'configured'`
 * @returns {Promise<CryptoKey[]>} non-extractable HMAC-SHA256 keys, current first
 */
async function ticketKeys(config) {
	const secret = config.sharedSecretBytes;
	if (secret === null) {
		throw new AtomsError('internal', 'ATOMS_SHARED_SECRET is not configured');
	}

	const keys = [await deriveTicketKey(secret)];
	const previous = config.sharedSecretPreviousBytes;
	if (previous !== null) {
		keys.push(await deriveTicketKey(previous));
	}
	return keys;
}

/**
 * Verify a presented ticket against the atom it claims to be for. Stateless:
 * everything here runs at the edge, before any DO is addressed, so a forged,
 * expired or mis-scoped ticket never costs an activation.
 *
 * Order: length cap; version/format; signature; payload shape; scope; expiry.
 * The signature is checked under the current shared secret and then, while a
 * rotation overlap is configured, under `ATOMS_SHARED_SECRET_PREVIOUS`; a
 * ticket signed under neither is `ticket_invalid`.
 *
 * Expiry is absolute and allows no clock skew: the ticket is expired the
 * moment this Worker's clock reaches `exp`. There is no skew setting to widen
 * it. The issuer chooses the lifetime and has the same wall clock available;
 * a tolerance here would only have blurred the one property the ticket
 * actually promises.
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
	const signed = encoder.encode(TICKET_SIGNING_PREFIX + payloadB64);
	let ok = false;
	if (sig !== null) {
		for (const key of await ticketKeys(config)) {
			if (await crypto.subtle.verify('HMAC', key, sig, signed)) {
				ok = true;
				break;
			}
		}
	}
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

	if (nowMs >= payload.exp) {
		throw new AtomsError('ticket_expired', 'the ticket has expired; issue a fresh one');
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
