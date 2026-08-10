/**
 * Worker entry: routing, auth, and error-envelope mapping.
 *
 * Routes (MVP spec §"Routing and auth"):
 *
 *   POST /invoke/:type/:id/:method   body {"args":[...]}
 *        -> 200 {"result":..., "atom":{"type":...,"id":...}}
 *        -> 4xx/5xx {"error":{"code","message","retryable"}}
 *   GET  /healthz                    -> {"ok":true}, never touches a DO
 *   GET  /debug/:type/:id/info       -> residency info, ATOMS_DEBUG_ENDPOINTS=1 only
 *
 * This is the invoke contract. `atoms/client` calls it directly; the
 * `/v1/{customer}` prefix it used to send is gone, because the Worker is
 * single-tenant and has no customer to disambiguate (M3, 2026-08-09 —
 * `docs/cloudflare-toolchain.md` §1). The retired platform's contract is
 * `docs/platform/api-contract.md`, kept as history only.
 *
 * The int64 tag (`{"$atoms_int64":"..."}`) is the wire form at this boundary
 * too: argument and result payloads pass through untouched, so a client can
 * send and receive exact 64-bit integers.
 */
import bundle from './bundle.generated.js';
import { loadConfig } from './config.js';
import { AtomsError, normalizeError, retryableFor, statusFor } from './errors.js';
import { decodeInt64Deep } from './int64.js';

export { AtomDurableObject } from './atom-do.js';

const ATOM_TYPE_RE = /^[A-Za-z_][A-Za-z0-9_]*$/;
const METHOD_RE = /^[A-Za-z_][A-Za-z0-9_]*$/;

/**
 * @param {unknown} body
 * @param {number} [status]
 * @returns {Response}
 */
function json(body, status = 200) {
	return new Response(JSON.stringify(body), {
		status,
		headers: { 'content-type': 'application/json; charset=utf-8' },
	});
}

/**
 * @param {string} code
 * @param {string} message
 * @param {Record<string, unknown>} [extra]
 * @returns {Response}
 */
function errorResponse(code, message, extra = {}) {
	return json(
		{ error: { code, message, retryable: retryableFor(code), ...extra } },
		statusFor(code)
	);
}

export default {
	/**
	 * @param {Request} request
	 * @param {Record<string, any>} env
	 * @returns {Promise<Response>}
	 */
	async fetch(request, env) {
		try {
			return await route(request, env);
		} catch (e) {
			const n = normalizeError(e);
			if (n.code === 'internal') {
				console.log(
					JSON.stringify({
						ts: new Date().toISOString(),
						level: 'error',
						source: 'host',
						msg: 'atoms.worker.unhandled',
						error: n.message,
					})
				);
				// Traces are never returned to the client.
				return errorResponse('internal', 'internal error');
			}
			return errorResponse(n.code, n.message);
		}
	},
};

/**
 * @param {Request} request
 * @param {Record<string, any>} env
 * @returns {Promise<Response>}
 */
async function route(request, env) {
	const config = loadConfig(env);
	const url = new URL(request.url);
	const parts = url.pathname.split('/').filter((s) => s.length > 0);

	if (parts.length === 1 && parts[0] === 'healthz') {
		if (request.method !== 'GET' && request.method !== 'HEAD') {
			return errorResponse('method_not_allowed', 'GET /healthz');
		}
		return json({ ok: true });
	}

	const authFailure = checkAuth(request, config);
	if (authFailure) return authFailure;

	if (parts[0] === 'invoke') {
		if (request.method !== 'POST') {
			return errorResponse('method_not_allowed', 'POST /invoke/:type/:id/:method');
		}
		if (parts.length !== 4) {
			return errorResponse('invalid_request', 'expected /invoke/:type/:id/:method');
		}
		return invoke(request, env, config, decodeSeg(parts[1]), decodeSeg(parts[2]), decodeSeg(parts[3]));
	}

	if (parts[0] === 'debug') {
		if (!config.debugEndpoints) {
			return errorResponse('not_found', 'debug endpoints are disabled (ATOMS_DEBUG_ENDPOINTS)');
		}
		if (request.method !== 'GET') {
			return errorResponse('method_not_allowed', 'GET /debug/:type/:id/info');
		}
		if (parts.length !== 4 || parts[3] !== 'info') {
			return errorResponse('invalid_request', 'expected /debug/:type/:id/info');
		}
		return debugInfo(env, config, decodeSeg(parts[1]), decodeSeg(parts[2]));
	}

	return errorResponse('not_found', `no route for ${request.method} ${url.pathname}`);
}

/**
 * Bearer auth, enabled only when the `ATOMS_APP_KEY` secret is set.
 *
 * @param {Request} request
 * @param {import('./config.js').AtomsConfig} config
 * @returns {Response|null}
 */
function checkAuth(request, config) {
	if (!config.appKey) return null;
	const header = request.headers.get('authorization') ?? '';
	const m = /^Bearer\s+(.+)$/i.exec(header.trim());
	if (!m || !timingSafeEqual(m[1], config.appKey)) {
		return errorResponse('unauthenticated', 'missing or invalid bearer token');
	}
	return null;
}

/**
 * Length-independent comparison. Not constant-time across lengths, which is
 * acceptable for a shared deployment key.
 *
 * @param {string} a
 * @param {string} b
 * @returns {boolean}
 */
function timingSafeEqual(a, b) {
	if (a.length !== b.length) return false;
	let diff = 0;
	for (let i = 0; i < a.length; i++) diff |= a.charCodeAt(i) ^ b.charCodeAt(i);
	return diff === 0;
}

/**
 * @param {string} segment
 * @returns {string}
 */
function decodeSeg(segment) {
	try {
		return decodeURIComponent(segment);
	} catch {
		return segment;
	}
}

/**
 * @param {Request} request
 * @param {Record<string, any>} env
 * @param {import('./config.js').AtomsConfig} config
 * @param {string} type
 * @param {string} id
 * @param {string} method
 * @returns {Promise<Response>}
 */
async function invoke(request, env, config, type, id, method) {
	validateType(type);
	validateId(id, config);
	if (!METHOD_RE.test(method)) {
		return errorResponse('invalid_request', `invalid method name ${JSON.stringify(method)}`);
	}
	const manifestFailure = checkManifest(type);
	if (manifestFailure) return manifestFailure;

	const declared = Number(request.headers.get('content-length') ?? '0');
	if (Number.isFinite(declared) && declared > config.maxRequestBytes) {
		return errorResponse('payload_too_large', `body exceeds ATOMS_MAX_REQUEST_BYTES (${config.maxRequestBytes})`);
	}

	const raw = await request.text();
	if (raw.length > config.maxRequestBytes) {
		return errorResponse('payload_too_large', `body exceeds ATOMS_MAX_REQUEST_BYTES (${config.maxRequestBytes})`);
	}

	/** @type {unknown[]} */
	let args = [];
	if (raw.trim() !== '') {
		/** @type {any} */
		let body;
		try {
			body = JSON.parse(raw);
		} catch (e) {
			return errorResponse('invalid_request', `request body is not valid JSON: ${String(e)}`);
		}
		if (typeof body !== 'object' || body === null || Array.isArray(body)) {
			return errorResponse('invalid_request', 'request body must be a JSON object');
		}
		if (body.args !== undefined) {
			if (!Array.isArray(body.args)) {
				return errorResponse('invalid_request', '"args" must be an array of positional arguments');
			}
			args = body.args;
		}
	}

	// Args cross to PHP untouched, so the int64 tags in them are validated —
	// not rewritten — here. Without this a client could send
	// {"$atoms_int64":"9223372036854775808"} and the guest-side decoder would
	// throw out of the turn loop, unwinding php.run() and poisoning the whole
	// residency. Validating before the DO is addressed keeps a bad request a
	// bad request. `decodeInt64Deep` throws `int64_range` (400); the decoded
	// copy is discarded.
	//
	// Nesting is checked for the same reason: PHP's json_decode() gives up past
	// its own depth limit and returns null, which the guest can only report as a
	// malformed host reply. A kilobyte of nested brackets must be a 400, not a
	// dead residency. The guest guards this too (bootstrap.php `turn_loop()`);
	// this keeps the failure a client error with a message that names the cause.
	try {
		assertJsonDepth(args, config.maxJsonDepth);
		decodeInt64Deep(args);
	} catch (e) {
		const n = normalizeError(e);
		return errorResponse(n.code === 'internal' ? 'invalid_request' : n.code, n.message);
	}

	const envelope = await callDurableObject(env, type, id, { kind: 'invoke', type, id, method, args });

	if (envelope.ok === true) {
		return json({ result: envelope.result ?? null, atom: { type, id } });
	}

	const error = envelope.error ?? {};
	const code = typeof error.code === 'string' ? error.code : 'internal';
	const message = typeof error.message === 'string' ? error.message : 'internal error';
	/** @type {Record<string, unknown>} */
	const extra = {};
	if (typeof error.class === 'string') extra.class = error.class;
	return errorResponse(code, code === 'internal' ? 'internal error' : message, extra);
}

/**
 * @param {Record<string, any>} env
 * @param {import('./config.js').AtomsConfig} config
 * @param {string} type
 * @param {string} id
 * @returns {Promise<Response>}
 */
async function debugInfo(env, config, type, id) {
	validateType(type);
	validateId(id, config);
	const manifestFailure = checkManifest(type);
	if (manifestFailure) return manifestFailure;

	const envelope = await callDurableObject(env, type, id, { kind: 'info', type, id });
	if (envelope.ok !== true) {
		const code = typeof envelope.error?.code === 'string' ? envelope.error.code : 'internal';
		return errorResponse(code, envelope.error?.message ?? 'internal error');
	}
	return json({ atom: { type, id }, info: envelope.info });
}

/**
 * Address the Atom's Durable Object and unwrap its internal envelope.
 *
 * DO identity is `idFromName(type + "\n" + id)`.
 *
 * @param {Record<string, any>} env
 * @param {string} type
 * @param {string} id
 * @param {Record<string, unknown>} call
 * @returns {Promise<any>}
 */
async function callDurableObject(env, type, id, call) {
	const ns = env.ATOMS;
	if (!ns || typeof ns.idFromName !== 'function') {
		throw new AtomsError('internal', 'the ATOMS Durable Object binding is not configured');
	}
	const stub = ns.get(ns.idFromName(`${type}\n${id}`));
	const res = await stub.fetch('https://atoms.internal/do', {
		method: 'POST',
		headers: { 'content-type': 'application/json' },
		body: JSON.stringify(call),
	});
	const text = await res.text();
	if (!res.ok) {
		throw new AtomsError('internal', `durable object returned ${res.status}: ${text.slice(0, 500)}`);
	}
	try {
		return JSON.parse(text);
	} catch (e) {
		throw new AtomsError('internal', `durable object returned non-JSON: ${String(e)}`);
	}
}

/**
 * Refuse a payload nested deeper than the guest's JSON decoder will follow.
 *
 * Iterative on purpose: a recursive walk would itself blow the JS stack on the
 * input it is meant to reject.
 *
 * @param {unknown} value
 * @param {number} maxDepth
 * @throws {AtomsError} `invalid_request`
 */
function assertJsonDepth(value, maxDepth) {
	/** @type {{v: unknown, d: number}[]} */
	const stack = [{ v: value, d: 1 }];
	while (stack.length) {
		const { v, d } = /** @type {{v: unknown, d: number}} */ (stack.pop());
		if (typeof v !== 'object' || v === null) continue;
		if (d > maxDepth) {
			throw new AtomsError(
				'invalid_request',
				`"args" is nested deeper than ATOMS_MAX_JSON_DEPTH (${maxDepth})`
			);
		}
		for (const child of Array.isArray(v) ? v : Object.values(v)) {
			stack.push({ v: child, d: d + 1 });
		}
	}
}

/**
 * The atom type must exist in the bundle manifest before any DO is touched.
 *
 * @param {string} type
 * @returns {Response|null}
 */
function checkManifest(type) {
	const atoms = bundle?.manifest?.atoms ?? {};
	if (!Object.prototype.hasOwnProperty.call(atoms, type)) {
		return errorResponse('unknown_atom_type', `atom type ${JSON.stringify(type)} is not in the deployed bundle`);
	}
	return null;
}

/**
 * @param {string} type
 */
function validateType(type) {
	if (!ATOM_TYPE_RE.test(type)) {
		throw new AtomsError('invalid_request', `invalid atom type ${JSON.stringify(type)}`);
	}
}

/**
 * @param {string} id
 * @param {import('./config.js').AtomsConfig} config
 */
function validateId(id, config) {
	if (id === '') {
		throw new AtomsError('invalid_request', 'atom id must not be empty');
	}
	if (new TextEncoder().encode(id).length > config.maxAtomIdBytes) {
		throw new AtomsError('invalid_request', `atom id exceeds ${config.maxAtomIdBytes} bytes`);
	}
	// Control characters (including the "\n" used to build the DO name) would
	// make two distinct {type,id} pairs collide on one Durable Object.
	if (/[\u0000-\u001f\u007f]/.test(id)) {
		throw new AtomsError('invalid_request', 'atom id must not contain control characters');
	}
}
