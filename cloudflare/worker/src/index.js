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
 *   GET  /ws/:type/:id               -> WebSocket upgrade; bearer OR a `?ticket=`
 *                                       issued by the application (browsers
 *                                       cannot set an Authorization header)
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
import { deriveBearer } from './derive.js';
import { AtomsError, normalizeError, retryableFor, statusFor } from './errors.js';
import { decodeInt64Deep } from './int64.js';
import { verifyTicket } from './tickets.js';
import { WS_CONN_ID_PLACEHOLDER, attachmentByteLength, buildAttachment } from './websockets.js';

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

	// The configuration gate, ahead of every credential check and every route
	// but /healthz. `ATOMS_SHARED_SECRET` is the root every key on this
	// boundary is derived from — the bearer, ticket signatures, callback
	// signatures — so without a usable one the Worker refuses everything and
	// says which variable and which rule (ATOMS-E105). `loadConfig()` stays
	// total, so /healthz still answers and the deployment is observably up and
	// observably broken.
	if (!config.sharedSecret.ok) {
		return errorResponse('misconfigured', config.sharedSecret.error);
	}

	const authFailure = await checkAuth(request, config);
	// /ws accepts a second credential: a connection ticket in the query string
	// (spec §Routing and auth), because a browser's `new WebSocket(url)` cannot
	// set an Authorization header. When the bearer check failed AND a ticket
	// key is present, the decision is deferred to wsUpgrade()'s stateless
	// ticket verification instead of refusing here. Every other route is
	// refused at this pre-dispatch gate, and a ticket buys nothing on any of
	// them: it is a credential for one atom's upgrade, nothing else.
	const wsTicketCandidate = parts[0] === 'ws' && url.searchParams.has('ticket');
	if (authFailure && !wsTicketCandidate) return authFailure;

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

	if (parts[0] === 'ws') {
		if (request.method !== 'GET') {
			return errorResponse('method_not_allowed', 'GET /ws/:type/:id');
		}
		if (parts.length !== 3) {
			return errorResponse('invalid_request', 'expected /ws/:type/:id');
		}
		return wsUpgrade(request, env, config, url, decodeSeg(parts[1]), decodeSeg(parts[2]), authFailure === null);
	}

	return errorResponse('not_found', `no route for ${request.method} ${url.pathname}`);
}

/**
 * Bearer auth. The expected token is `HKDF(ATOMS_SHARED_SECRET,
 * "atoms/bearer/v1")` as standard base64 — the secret itself is never a bearer
 * and never travels, so a leaked `Authorization` header compromises invocation
 * only. `atoms token` prints the same value for operators curling the Worker.
 *
 * `ATOMS_BEARER_AUTH=disabled` skips the comparison entirely, for a deployment
 * behind an authenticating proxy such as Cloudflare Access.
 *
 * During a rotation window the token is compared against `bearer(current)` and
 * then, when `ATOMS_SHARED_SECRET_PREVIOUS` is set, `bearer(previous)`,
 * accepting on the first match. Try-both, never a key selector: the previous
 * secret is an operator-provisioned fallback tried unconditionally, never
 * chosen by an attacker-controlled header.
 *
 * @param {Request} request
 * @param {import('./config.js').AtomsConfig} config with `sharedSecret.ok`
 * @returns {Promise<Response|null>}
 */
async function checkAuth(request, config) {
	if (config.bearerAuth === 'disabled') return null;

	const header = request.headers.get('authorization') ?? '';
	const m = /^Bearer\s+(.+)$/i.exec(header.trim());
	if (!m) return errorResponse('unauthenticated', 'missing or invalid bearer token');
	const presented = m[1];

	// Unreachable past the configuration gate — the union's `ok: false` branch
	// never gets here — but a typed internal failure rather than a cast that
	// would derive from nothing.
	if (!config.sharedSecret.ok) {
		throw new AtomsError('internal', 'ATOMS_SHARED_SECRET is not configured');
	}
	if (timingSafeEqual(presented, await deriveBearer(config.sharedSecret.bytes))) return null;

	const previous = config.sharedSecret.previousBytes;
	if (previous !== null && timingSafeEqual(presented, await deriveBearer(previous))) return null;

	return errorResponse('unauthenticated', 'missing or invalid bearer token');
}

/**
 * Length-independent comparison. Not constant-time across lengths, which is
 * acceptable for a derived deployment-wide bearer.
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
 * `GET /ws/:type/:id` — validate everything, THEN
 * forward the raw upgrade `Request` to the Atom's Durable Object stub. The
 * stub's `Response` (a 101 carrying `webSocket`, or a JSON error envelope
 * `atom-do.js` built itself) is returned untouched: an upgrade cannot go
 * through `callDurableObject()`'s JSON envelope, because workerd needs the
 * real `Request`/`Response` pair to hand the `webSocket` back to the client.
 *
 * @param {Request} request
 * @param {Record<string, any>} env
 * @param {import('./config.js').AtomsConfig} config
 * @param {URL} url
 * @param {string} type
 * @param {string} id
 * @param {boolean} bearerOk whether checkAuth() passed in route()
 * @returns {Promise<Response>}
 */
async function wsUpgrade(request, env, config, url, type, id, bearerOk) {
	// Ticket verification runs before anything else looks at the request
	// (spec §Routing and auth step 3): a caller without a valid credential
	// must not be able to probe which atom types are deployed, so the
	// unknown_atom_type refusal below is reachable only with a verified
	// ticket or a valid bearer. Three postures:
	//   - ATOMS_BEARER_AUTH=required + a valid bearer: any ticket is stripped
	//     unverified (a bearer holder is fully trusted and needs no claims);
	//   - required + bearer absent/invalid: route() only let this through
	//     because a ticket key is present — verify it, signature included;
	//   - ATOMS_BEARER_AUTH=disabled: no credential is required, and a ticket
	//     that IS presented is fully verified, signature included.
	const trustedBearer = config.bearerAuth === 'required' && bearerOk;
	/** @type {{claims: Record<string, string>, jti: string, exp: number}|null} */
	let verified = null;
	if (!trustedBearer) {
		const tickets = url.searchParams.getAll('ticket');
		if (tickets.length > 0) {
			// Last occurrence wins, matching parseWsParams's repeat-key rule.
			verified = await verifyTicket(config, tickets[tickets.length - 1], type, id, Date.now());
		}
	}

	const upgradeHeader = (request.headers.get('upgrade') ?? '').toLowerCase();
	if (upgradeHeader !== 'websocket') {
		return errorResponse('invalid_request', 'expected "Upgrade: websocket"');
	}

	validateType(type);
	validateId(id, config);

	const eligibilityFailure = checkWsEligibility(type);
	if (eligibilityFailure) return eligibilityFailure;

	const params = parseWsParams(url, config);
	// Ticket claims merge OVER the browser's params — server wins — so a
	// claim like client_id reaches onConnect as an ordinary param the browser
	// cannot forge or override. `channels` can never be a claim (refused at
	// mint, refused again in verifyTicket), so channel membership always
	// comes from the query string. Null prototype preserved from both sides.
	const merged = Object.assign(Object.create(null), params, verified ? verified.claims : {});
	const channels = parseWsChannels(merged.channels, config);
	assertWsAcceptBudgets(channels, config);

	const ns = env.ATOMS;
	if (!ns || typeof ns.idFromName !== 'function') {
		throw new AtomsError('internal', 'the ATOMS Durable Object binding is not configured');
	}
	const stub = ns.get(ns.idFromName(`${type}\n${id}`));

	// Everything the DO needs that is NOT already on the forwarded Request
	// (method, headers including Upgrade) crosses in one `call` query key,
	// which cannot collide with the client's own params — those were
	// re-encoded inside it, not merged with it. A verified ticket
	// contributes only its merged claims above; the ticket itself never
	// crosses to the DO.
	const call = encodeURIComponent(JSON.stringify({ type, id, params: merged, channels }));
	return stub.fetch(new Request(`https://atoms.internal/ws?call=${call}`, request));
}

/**
 * The two manifest refusals the `/ws` upgrade makes: an unknown type, and a
 * type that declares no WebSocket handler. The upgrade is the authority on
 * both — an issuer works from whatever manifest it has locally, which can lag
 * this deployment, so it never pre-judges either question.
 *
 * @param {string} type
 * @returns {Response|null}
 */
function checkWsEligibility(type) {
	const manifestFailure = checkManifest(type);
	if (manifestFailure) return manifestFailure;

	// Absent flag => allowed; explicit false => the type declares no WebSocket
	// handlers and the route refuses before any DO is touched.
	const entry = bundle?.manifest?.atoms?.[type] ?? {};
	if (entry.websocket === false) {
		return errorResponse(
			'not_supported',
			`atom type ${JSON.stringify(type)} does not declare a WebSocket handler ("websocket": false in the manifest)`
		);
	}
	return null;
}

/** Channel name format: `^[A-Za-z0-9][A-Za-z0-9._:@-]*$`. */
const CHANNEL_NAME_RE = /^[A-Za-z0-9][A-Za-z0-9._:@-]*$/;

/**
 * The flat `string -> string` map `onConnect` receives: every query key as
 * sent, last value winning for repeats (matches `URLSearchParams` iteration
 * order), `channels` included verbatim — except the reserved `ticket` key,
 * which is the connection credential (spec §Routing and auth): stripped in
 * every auth mode, excluded from both budgets, never delivered to PHP.
 *
 * @param {URL} url
 * @param {import('./config.js').AtomsConfig} config
 * @returns {Record<string, string>}
 */
function parseWsParams(url, config) {
	// Null-prototype on purpose: with an ordinary object literal, a client
	// sending `?__proto__=x` writes the one key whose assignment is not an own
	// property, so the param would be silently dropped from the map the spec
	// calls "every query key as sent". `Object.create(null)` has no `__proto__`
	// setter to intercept it, and JSON.stringify()/Object.entries() treat a
	// null-prototype object exactly like any other plain object.
	/** @type {Record<string, string>} */
	const params = Object.create(null);
	let count = 0;
	for (const [key, value] of url.searchParams) {
		// The strip happens before the count and before the byte total below,
		// so a browser URL at exactly the param caps plus a ticket still fits.
		if (key === 'ticket') continue;
		if (!Object.prototype.hasOwnProperty.call(params, key)) count++;
		params[key] = value;
	}
	if (count > config.wsMaxParams) {
		throw new AtomsError(
			'invalid_request',
			`the connect request has ${count} query parameters, over ATOMS_WS_MAX_PARAMS (${config.wsMaxParams})`
		);
	}

	let totalBytes = 0;
	for (const [key, value] of Object.entries(params)) {
		totalBytes += new TextEncoder().encode(key).length + new TextEncoder().encode(value).length;
	}
	if (totalBytes > config.wsMaxParamBytes) {
		throw new AtomsError(
			'invalid_request',
			`the connect request's query parameters total ${totalBytes} bytes, over ATOMS_WS_MAX_PARAM_BYTES (${config.wsMaxParamBytes})`
		);
	}

	return params;
}

/**
 * Parse and validate `?channels=a,b,c` into the de-duplicated, ordered list
 * of channel names a new connection joins. Never silently
 * truncates: every violation is a named `invalid_request`.
 *
 * @param {string|undefined} raw
 * @param {import('./config.js').AtomsConfig} config
 * @returns {string[]}
 */
function parseWsChannels(raw, config) {
	if (raw === undefined || raw.trim() === '') return [];

	/** @type {string[]} */
	const channels = [];
	const seen = new Set();
	for (const part of raw.split(',')) {
		const name = part.trim();
		if (name === '' || seen.has(name)) continue;
		seen.add(name);

		if (!CHANNEL_NAME_RE.test(name)) {
			throw new AtomsError('invalid_request', `invalid channel name ${JSON.stringify(name)}`);
		}
		if (new TextEncoder().encode(name).length > config.wsMaxChannelNameBytes) {
			throw new AtomsError(
				'invalid_request',
				`channel name ${JSON.stringify(name)} exceeds ATOMS_WS_MAX_CHANNEL_NAME_BYTES (${config.wsMaxChannelNameBytes})`
			);
		}
		channels.push(name);
	}

	if (channels.length > config.wsMaxChannels) {
		throw new AtomsError(
			'invalid_request',
			`the connect request names ${channels.length} channels, over ATOMS_WS_MAX_CHANNELS (${config.wsMaxChannels})`
		);
	}
	// The derived budget, stated once here so it cannot drift from
	// the connection tag plus one channel tag per channel.
	if (1 + channels.length > config.wsMaxTagsPerConnection) {
		throw new AtomsError(
			'invalid_request',
			`1 connection tag + ${channels.length} channel tags exceeds ATOMS_WS_MAX_TAGS_PER_CONNECTION ` +
				`(${config.wsMaxTagsPerConnection})`
		);
	}

	return channels;
}

/**
 * The two host-side budgets an accepted connection has to fit, checked at the
 * EDGE — before any Durable Object is addressed.
 *
 * Both were previously enforced only inside the DO, after `ensureActive()` had
 * already booted PHP, applied migrations and run `onActivation()`: a request
 * that was always going to be refused could therefore provoke (and be billed
 * for) a full activation, and one that named 8 long channels could do it
 * repeatedly. `ATOMS_WS_MAX_TAG_BYTES` was not enforced anywhere at all.
 *
 * The attachment is sized against a placeholder id of exactly the length the
 * real `crypto.randomUUID()` will have, so the number checked here is the
 * number the accept path will produce. `atom-do.js` keeps its own attachment
 * check as defence in depth — it is the side that actually calls
 * `serializeAttachment()`.
 *
 * @param {string[]} channels already validated, de-duplicated, in accepted order
 * @param {import('./config.js').AtomsConfig} config
 * @throws {AtomsError} `invalid_request`
 */
function assertWsAcceptBudgets(channels, config) {
	const encoder = new TextEncoder();

	const connTagBytes = encoder.encode(config.wsConnTagPrefix + WS_CONN_ID_PLACEHOLDER).length;
	if (connTagBytes > config.wsMaxTagBytes) {
		throw new AtomsError(
			'invalid_request',
			`the connection tag (ATOMS_WS_CONN_TAG_PREFIX + a connection id) is ${connTagBytes} bytes, ` +
				`over ATOMS_WS_MAX_TAG_BYTES (${config.wsMaxTagBytes})`
		);
	}

	for (const name of channels) {
		const tag = config.wsChannelTagPrefix + name;
		const bytes = encoder.encode(tag).length;
		if (bytes > config.wsMaxTagBytes) {
			throw new AtomsError(
				'invalid_request',
				`the tag for channel ${JSON.stringify(name)} is ${bytes} bytes, over ` +
					`ATOMS_WS_MAX_TAG_BYTES (${config.wsMaxTagBytes})`
			);
		}
	}

	const attachmentBytes = attachmentByteLength(buildAttachment(WS_CONN_ID_PLACEHOLDER, channels));
	if (attachmentBytes > config.wsMaxAttachmentBytes) {
		throw new AtomsError(
			'invalid_request',
			`this connection's channel list makes its attachment ${attachmentBytes} bytes, over ` +
				`ATOMS_WS_MAX_ATTACHMENT_BYTES (${config.wsMaxAttachmentBytes})`
		);
	}
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
