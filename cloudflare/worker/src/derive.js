/**
 * Key derivation from the one shared secret (`docs/shared-secret.md`, the
 * decision record and the normative contract).
 *
 * `ATOMS_SHARED_SECRET` is 32 random bytes, base64-encoded, configured
 * identically on the monolith and the Worker and **never transmitted**. Every
 * key on the boundary is derived from it with HKDF-SHA256 domain separation —
 * empty salt, a fixed per-purpose `info` string, 32-byte output — so a leak of
 * any one derived value cannot be walked back to the root or sideways to the
 * other two:
 *
 *   atoms/bearer/v1     -> the `Authorization: Bearer` value (standard base64)
 *   atoms/ws-ticket/v1  -> the connection-ticket HMAC key (`tickets.js`)
 *   atoms/callback/v1   -> the callback HMAC key (`callbacks.js`)
 *
 * The IKM is always the **decoded 32 raw bytes**, never the base64 string.
 * Decoding first is what makes the two languages provably agree: PHP's
 * `hash_hkdf('sha256', $ikm, 32, $info, '')` and WebCrypto's
 * `deriveBits({name:'HKDF', hash:'SHA-256', salt: new Uint8Array(0), info},
 * ikm, 256)` are byte-identical over the same IKM. Reference vector, pinned in
 * both suites: the secret `AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=`
 * derives the bearer `Dx6RY9LS43pOQhM4PMdaUWx3lk9mfyiiJZFfJtvl9E0=`.
 *
 * The two HMAC keys are imported **non-extractable**: the Worker never needs
 * to export them, host JS does all the signing, and the guest never sees the
 * secret or any derived key — see `callbacks.js` for why that matters.
 */

const encoder = new TextEncoder();

/**
 * Domain-separation labels. Protocol constants, never env-tunable: two
 * deployments that disagree on one simply cannot exchange the thing it keys,
 * which is the point.
 */
export const BEARER_HKDF_INFO = 'atoms/bearer/v1';
export const TICKET_HKDF_INFO = 'atoms/ws-ticket/v1';
export const CALLBACK_HKDF_INFO = 'atoms/callback/v1';

/**
 * @param {Uint8Array} bytes
 * @returns {string} standard base64 (RFC 4648, padded)
 */
function bytesToBase64(bytes) {
	let binary = '';
	for (let i = 0; i < bytes.length; i++) binary += String.fromCharCode(bytes[i]);
	return btoa(binary);
}

/**
 * Per-purpose memos, keyed on the secret's canonical base64: isolates outlive
 * configs (a config is rebuilt per request), and a rotation window has two
 * live secrets at once, so each purpose keeps a slot per secret.
 *
 * @type {Map<string, Promise<string>>}
 */
const bearerMemo = new Map();
/** @type {Map<string, Promise<CryptoKey>>} */
const ticketKeyMemo = new Map();
/** @type {Map<string, Promise<CryptoKey>>} */
const callbackKeyMemo = new Map();

/**
 * A deployment has two secrets live at most (current and previous); one local
 * isolate can be fed a series of `--var ATOMS_SHARED_SECRET` values across dev
 * runs. Past a handful of distinct secrets the memos are dropped wholesale, so
 * they never become an unbounded retainer of key material. Hygiene rather than
 * a capacity knob: nothing operational depends on the number, and a dropped
 * entry costs one re-derivation.
 */
const MEMO_SOFT_LIMIT = 4;

/**
 * @template T
 * @param {Map<string, Promise<T>>} memo
 * @param {string} id
 * @param {() => Promise<T>} produce
 * @returns {Promise<T>}
 */
function memoize(memo, id, produce) {
	const hit = memo.get(id);
	if (hit) return hit;
	if (memo.size >= MEMO_SOFT_LIMIT) memo.clear();
	const promise = produce();
	memo.set(id, promise);
	return promise;
}

/**
 * @param {Uint8Array} secretBytes the decoded 32 raw bytes
 * @returns {Promise<CryptoKey>} the IKM, imported as HKDF key material
 */
function importIkm(secretBytes) {
	return crypto.subtle.importKey('raw', secretBytes, 'HKDF', false, ['deriveBits', 'deriveKey']);
}

/**
 * @param {string} info
 * @returns {HkdfParams}
 */
function hkdfParams(info) {
	return { name: 'HKDF', hash: 'SHA-256', salt: new Uint8Array(0), info: encoder.encode(info) };
}

/**
 * The wire bearer for one secret: `HKDF(secret, "atoms/bearer/v1")` as
 * standard base64 — padded, exactly 44 characters. The length and encoding are
 * part of the contract, not an implementation detail.
 *
 * The root itself is never a bearer and never travels: a leaked
 * `Authorization` header (proxy logs, APM header capture, an exception
 * reporter that dumps requests) compromises invocation only.
 *
 * @param {Uint8Array} secretBytes the decoded 32 raw bytes
 * @returns {Promise<string>}
 */
export function deriveBearer(secretBytes) {
	return memoize(bearerMemo, bytesToBase64(secretBytes), async () => {
		const material = await importIkm(secretBytes);
		const bits = await crypto.subtle.deriveBits(hkdfParams(BEARER_HKDF_INFO), material, 256);
		return bytesToBase64(new Uint8Array(bits));
	});
}

/**
 * The connection-ticket key: non-extractable HMAC-SHA256, `verify` only — the
 * application signs tickets and this Worker verifies them, so the Worker has
 * no use for a signing capability on this key.
 *
 * Called once per live secret during a rotation window, which is what the
 * per-secret memo slot above is for.
 *
 * @param {Uint8Array} secretBytes the decoded 32 raw bytes
 * @returns {Promise<CryptoKey>}
 */
export function deriveTicketKey(secretBytes) {
	return memoize(ticketKeyMemo, bytesToBase64(secretBytes), () =>
		deriveHmacKey(secretBytes, TICKET_HKDF_INFO, ['verify'])
	);
}

/**
 * The callback signing key: non-extractable HMAC-SHA256, `sign` only — the
 * Worker signs callbacks and the monolith verifies them, so the Worker has no
 * legitimate use for `verify` here.
 *
 * @param {Uint8Array} secretBytes the decoded 32 raw bytes
 * @returns {Promise<CryptoKey>}
 */
export function deriveCallbackKey(secretBytes) {
	return memoize(callbackKeyMemo, bytesToBase64(secretBytes), () =>
		deriveHmacKey(secretBytes, CALLBACK_HKDF_INFO, ['sign'])
	);
}

/**
 * `length: 256` is part of the contract, not a default worth inheriting: an
 * HMAC key's length defaults to the hash's BLOCK size (512 bits for SHA-256),
 * which would key the MAC with 64 derived bytes. The derivation is specified
 * as a 32-byte output, and the monolith keys `hash_hmac()` with exactly those
 * 32 bytes from `hash_hkdf()`, so the two sides agree only at 256 bits.
 *
 * @param {Uint8Array} secretBytes
 * @param {string} info
 * @param {KeyUsage[]} usages
 * @returns {Promise<CryptoKey>}
 */
async function deriveHmacKey(secretBytes, info, usages) {
	const material = await importIkm(secretBytes);
	return crypto.subtle.deriveKey(
		hkdfParams(info),
		material,
		{ name: 'HMAC', hash: 'SHA-256', length: 256 },
		false,
		usages
	);
}
