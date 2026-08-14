#!/usr/bin/env node

/**
 * Conformance suite for Atoms-on-Cloudflare MVP.
 *
 * Runs the conformance contract against a live Worker URL. For callback
 * checks, this suite plays the monolith with a `node:http` listener bound to
 * loopback that verifies Ed25519 signatures using `node:crypto`. WebSocket
 * checks use Node's built-in global `WebSocket`, so the suite needs no extra
 * client dependency.
 *
 * Config via env:
 *   ATOMS_BASE_URL (required)
 *   ATOMS_APP_KEY (optional bearer token)
 *   ATOMS_EVICTION_WAIT_MS (default 12500)
 *   ATOMS_CALLBACK_PORT (default: the port recorded in test/.callback-key.json)
 *   ATOMS_TURN_DEADLINE_MS (required for checks 15a/15b; must match the value
 *     the Worker was started with — never defaulted here, so no capacity
 *     number is written into the suite)
 *   ATOMS_REQUIRE_CALLBACK_CHECKS=1 (turn the callback-channel skips into
 *     failures — CI sets it, so 13-17 can never go quietly missing)
 *   ATOMS_TEST_JOB_DELAY_MS (default 400; how long this suite's listener holds
 *     a `kind=job` response open. A TEST-HARNESS value, not a Worker setting:
 *     it exists so checks 16/17 can prove the Worker AWAITED the delivery
 *     rather than merely started it, by comparing when the job response was
 *     sent against when the invoke response arrived)
 *   ATOMS_SKIP=n,m (comma-separated check numbers to skip)
 */

import { createPublicKey, randomBytes, verify as verifyEd25519 } from 'node:crypto';
import { createServer, request as httpRequest } from 'node:http';
import { request as httpsRequest } from 'node:https';
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { renderMatrixDoc } from '../scripts/gen-pdo-matrix.mjs';

const BASE_URL = process.env.ATOMS_BASE_URL;
const APP_KEY = process.env.ATOMS_APP_KEY;
const EVICTION_WAIT_MS = parseInt(process.env.ATOMS_EVICTION_WAIT_MS || '12500');
const TURN_DEADLINE_MS = process.env.ATOMS_TURN_DEADLINE_MS ? parseInt(process.env.ATOMS_TURN_DEADLINE_MS, 10) : null;
// A skip is the right answer for a Worker that legitimately has no callback
// channel, and the wrong answer in CI — where a missing key file would silently
// delete five checks from the run. Set it there; unset everywhere it is honest.
const REQUIRE_CALLBACK_CHECKS = /^(1|true|yes|on)$/i.test(process.env.ATOMS_REQUIRE_CALLBACK_CHECKS || '');
// Must match the values the Worker was started with (never defaulted here,
// same rule as ATOMS_TURN_DEADLINE_MS above) — check 29's result-set size
// guard (M1 design §4.4). Both absent => check 29 skips.
const SQL_MAX_ROWS = process.env.ATOMS_SQL_MAX_ROWS ? parseInt(process.env.ATOMS_SQL_MAX_ROWS, 10) : null;
const SQL_MAX_RESULT_BYTES = process.env.ATOMS_SQL_MAX_RESULT_BYTES
    ? parseInt(process.env.ATOMS_SQL_MAX_RESULT_BYTES, 10)
    : null;
// Same anti-silent-deletion device as ATOMS_REQUIRE_CALLBACK_CHECKS: a run
// that started the Worker with both cap vars set must not let check 29
// quietly skip.
const REQUIRE_SQL_CAP_CHECKS = /^(1|true|yes|on)$/i.test(process.env.ATOMS_REQUIRE_SQL_CAP_CHECKS || '');
// Harness-side, not Worker-side: how long the in-suite listener holds a job
// response open (see the header). Long enough that "the invoke response came
// back first" is unambiguous, short enough not to lengthen the run.
const JOB_DELAY_MS = parseInt(process.env.ATOMS_TEST_JOB_DELAY_MS || '400', 10);
const SKIP = (process.env.ATOMS_SKIP || '')
    .split(',')
    .map(s => parseInt(s.trim()))
    .filter(n => !isNaN(n));

if (!BASE_URL) {
    console.error('Error: ATOMS_BASE_URL env var is required');
    process.exit(1);
}

const baseUrl = BASE_URL.replace(/\/$/, '');
const __dirname = dirname(fileURLToPath(import.meta.url));

// ---------------------------------------------------------------- utilities

let passCount = 0;
let failCount = 0;
const results = [];

function pass(checkNum, name, msg = '') {
    passCount++;
    results.push({ checkNum, name, status: 'PASS', msg });
    console.log(`✓ CHECK ${checkNum}: ${name}${msg ? ` — ${msg}` : ''}`);
}

function fail(checkNum, name, msg = '') {
    failCount++;
    results.push({ checkNum, name, status: 'FAIL', msg });
    console.log(`✗ CHECK ${checkNum}: ${name}${msg ? ` — ${msg}` : ''}`);
}

/**
 * A check that could not run for a reason that is not the Worker's fault (no
 * callback listener, no configured turn deadline, no configured result-set
 * caps). Not a failure: it must not fail a run against a Worker that
 * legitimately has no callback channel or no cap vars set.
 *
 * `require_` (default `REQUIRE_CALLBACK_CHECKS`) is the "this environment
 * asserted the prerequisite exists" flag: when it is set, a skip means the
 * harness is broken rather than the check inapplicable — so it fails instead.
 * Check 29 passes `REQUIRE_SQL_CAP_CHECKS` for its own, independent gate.
 *
 * `envVar` (M1 review round 2, R12) names the specific environment variable
 * whose absence caused the skip, so a reader of the failure/skip line (or of
 * `results`) is told exactly what to set, rather than a generic "unavailable"
 * that leaves them re-reading this file's setup docs to find it. Optional:
 * some skips (e.g. a missing `test/.callback-key.json`, not itself an env
 * var) have no single variable to name and pass none.
 */
function skip(checkNum, name, msg = '', require_ = REQUIRE_CALLBACK_CHECKS, envVar = null) {
    const full = envVar ? `${msg} (env var: ${envVar})` : msg;
    if (require_) {
        fail(checkNum, name, `${full || 'unavailable'} — but the run asserted this must be available, so this must run`);
        return;
    }
    results.push({ checkNum, name, status: 'SKIP', msg: full });
    console.log(`⊘ CHECK ${checkNum}: ${name} — skipped${full ? ` (${full})` : ''}`);
}

/** Make an HTTP request. */
async function request(method, path, body = null) {
    const url = new URL(path, baseUrl).toString();
    const opts = { method };

    if (APP_KEY) {
        opts.headers = { Authorization: `Bearer ${APP_KEY}` };
    }

    if (body) {
        opts.headers = { ...opts.headers, 'Content-Type': 'application/json' };
        opts.body = JSON.stringify(body);
    }

    const res = await fetch(url, opts);
    const text = await res.text();

    let data;
    try {
        data = JSON.parse(text);
    } catch {
        data = { _raw: text };
    }

    return { status: res.status, headers: res.headers, data };
}

/** Helper to detect if a value is an int64 tag. */
function isInt64Tag(val) {
    return (
        typeof val === 'object' &&
        val !== null &&
        '$atoms_int64' in val
    );
}

/** Helper to parse int64 tag. */
function parseInt64(val) {
    if (isInt64Tag(val)) {
        return BigInt(val.$atoms_int64);
    }
    return BigInt(val);
}

/** Helper to invoke an Atom method. */
async function invoke(type, id, method, args = []) {
    return request('POST', `/invoke/${type}/${id}/${method}`, { args });
}

/** Residency info from GET /debug/:type/:id/info (ATOMS_DEBUG_ENDPOINTS=1). */
async function debugInfo(type, id) {
    const { status, data } = await request('GET', `/debug/${type}/${id}/info`);
    if (status !== 200) {
        throw new Error(
            `GET /debug/${type}/${id}/info returned ${status}: ${JSON.stringify(data)}. ` +
                'The suite needs ATOMS_DEBUG_ENDPOINTS=1.'
        );
    }
    return data.info;
}

/** Encode a JS BigInt the way the wire wants it: tagged only when it must be. */
function wire(v) {
    return v > 9007199254740991n || v < -9007199254740991n
        ? { $atoms_int64: v.toString() }
        : Number(v);
}

/** A fresh atom id per run, so a re-run never inherits durable state. */
const RUN = `r${Date.now().toString(36)}`;
const atomId = (name) => `${name}-${RUN}`;

// -------------------------------------------------------------- websockets

/**
 * A SYNTACTICALLY COMPLETE WebSocket upgrade — valid `Sec-WebSocket-Key`,
 * `Sec-WebSocket-Version: 13`, `Connection: Upgrade`, `Upgrade: websocket` —
 * carrying a WORKER-LEVEL violation (too many channels, or a type that declares
 * no WebSocket handler) that the route must refuse with an ordinary JSON error
 * envelope BEFORE any DO is touched. The handshake is complete
 * on purpose: a deployed `https://` target sits behind Cloudflare's edge, which
 * rejects an INCOMPLETE handshake (e.g. a missing `Sec-WebSocket-Key`) itself,
 * before the Worker can answer — so the probe would never reach the code it is
 * meant to exercise. A complete handshake passes the edge's own check and lands
 * on the Worker, where the worker-level violation yields the 400/501 JSON the
 * check asserts (an error case never becomes a 101, on http or https alike).
 *
 * Built on `node:http`/`node:https` rather than `fetch()` because
 * `Upgrade`/`Connection` are the two headers a spec-compliant fetch
 * implementation may refuse to let a caller set by hand; the client is chosen
 * from the base URL's scheme so a deployed `https://` base URL works instead of
 * dying with Node's `Protocol "https:" not supported`.
 *
 * @param {string} path
 * @returns {Promise<{status: number, data: any}>}
 */
function wsHandshakeAttempt(path) {
    return new Promise((resolve, reject) => {
        const url = new URL(path, baseUrl);
        const headers = {
            Connection: 'Upgrade',
            Upgrade: 'websocket',
            'Sec-WebSocket-Key': randomBytes(16).toString('base64'),
            'Sec-WebSocket-Version': '13',
        };
        if (APP_KEY) headers.Authorization = `Bearer ${APP_KEY}`;
        const requestFn = url.protocol === 'https:' ? httpsRequest : httpRequest;

        // The probe must SETTLE on every path, or a regression turns a failed
        // assertion into a hung suite. Guard against double-settle and always
        // clear the timeout backstop.
        let settled = false;
        let timer = null;
        const finish = (fn, arg) => {
            if (settled) return;
            settled = true;
            if (timer) clearTimeout(timer);
            fn(arg);
        };

        const req = requestFn(url, { method: 'GET', headers }, (res) => {
            const chunks = [];
            res.on('data', (c) => chunks.push(c));
            res.on('end', () => {
                const text = Buffer.concat(chunks).toString('utf8');
                let data;
                try {
                    data = JSON.parse(text);
                } catch {
                    data = { _raw: text };
                }
                finish(resolve, { status: res.statusCode, data });
            });
        });

        // A '101 Switching Protocols' means the Worker WRONGLY accepted a bad
        // upgrade — Node emits 'upgrade' (not the 'response' callback) for it,
        // so without this handler the promise never settles and the check
        // hangs instead of failing. Surface it as a 101 result so the caller's
        // status assertion (which expects a 4xx refusal) fails loudly. Destroy
        // the socket so the accepted connection does not leak.
        req.on('upgrade', (res, socket) => {
            socket.destroy();
            finish(resolve, { status: res.statusCode, data: { _unexpectedUpgrade: true } });
        });

        req.on('error', (e) => finish(reject, e));

        // Backstop: no path may hang the suite.
        timer = setTimeout(() => {
            req.destroy();
            finish(reject, new Error(`ws handshake probe timed out for ${path}`));
        }, 5000);

        req.end();
    });
}

/**
 * Open a WebSocket to the worker's `/ws` route and wait for it to connect.
 * Node 22's global `WebSocket` accepts `{headers: {Authorization: ...}}`
 * (an undici extension), so this works identically whether
 * `ATOMS_APP_KEY` is set or not — no ticket, no query-string credential.
 *
 * The returned handle collects inbound frames into arrival order; `.next()`
 * awaits (and consumes) the next one, whether it already arrived or is still
 * to come, with a bounded timeout so a check fails instead of hanging.
 *
 * @param {string} path e.g. `/ws/Room/<id>?channels=lobby`
 * @returns {Promise<{
 *   send: (data: string|Uint8Array) => void,
 *   next: (timeoutMs?: number) => Promise<string|ArrayBuffer>,
 *   close: (code?: number, reason?: string) => Promise<void>,
 * }>}
 */
function openSocket(path) {
    const url = new URL(path, baseUrl).toString().replace(/^http/, 'ws');
    const opts = APP_KEY ? { headers: { Authorization: `Bearer ${APP_KEY}` } } : undefined;
    const ws = opts ? new WebSocket(url, opts) : new WebSocket(url);
    ws.binaryType = 'arraybuffer';

    /** @type {(string|ArrayBuffer)[]} */
    const frames = [];
    /** @type {((frame: string|ArrayBuffer) => void)[]} */
    const waiters = [];

    ws.addEventListener('message', (e) => {
        if (waiters.length) waiters.shift()(e.data);
        else frames.push(e.data);
    });

    const opened = new Promise((resolve, reject) => {
        ws.addEventListener('open', () => resolve(), { once: true });
        ws.addEventListener('error', () => reject(new Error(`ws open failed for ${path}`)), { once: true });
        setTimeout(() => reject(new Error(`ws open timed out for ${path}`)), 5000);
    });

    return opened.then(() => ({
        send(data) {
            ws.send(data);
        },
        async next(timeoutMs = 3000) {
            if (frames.length) return frames.shift();
            return new Promise((resolve, reject) => {
                const timer = setTimeout(() => {
                    const i = waiters.indexOf(onFrame);
                    if (i !== -1) waiters.splice(i, 1);
                    reject(new Error(`timed out waiting for a frame on ${path} after ${timeoutMs}ms`));
                }, timeoutMs);
                const onFrame = (frame) => {
                    clearTimeout(timer);
                    resolve(frame);
                };
                waiters.push(onFrame);
            });
        },
        async close(code = 1000, reason = '') {
            // 0 = CONNECTING, 1 = OPEN — only those are legal to close().
            if (ws.readyState === 0 || ws.readyState === 1) {
                try {
                    ws.close(code, reason);
                } catch {
                    /* already closing */
                }
            }
            if (ws.readyState === 3) return; // CLOSED
            await new Promise((resolve) => {
                ws.addEventListener('close', () => resolve(), { once: true });
                setTimeout(resolve, 3000);
            });
        },
    }));
}

// ------------------------------------------------------- callback listener

/**
 * Wrap a raw 32-byte Ed25519 public key in the fixed SPKI DER header so
 * node:crypto can import it. Mirrors the PKCS8 trick on the signing side —
 * same idea, the public-key encoding.
 */
function importRawEd25519PublicKey(b64) {
    const SPKI_PREFIX = Buffer.from('302a300506032b6570032100', 'hex');
    const raw = Buffer.from(b64, 'base64');
    return createPublicKey({ key: Buffer.concat([SPKI_PREFIX, raw]), format: 'der', type: 'spki' });
}

/**
 * The in-suite "monolith": verifies every callback the Worker sends and
 * answers the fixture's two Methods (`echoBig`, `stall`) and its one job
 * kind. Bound to 127.0.0.1 only — this keeps "tests never hit the network"
 * literally true.
 *
 * Inherits the opaque-body invariant: the wide-integer
 * argument to `echoBig` is extracted and echoed back TEXTUALLY, via regex on
 * the raw body, never through `JSON.parse` — which would silently round an
 * int64-range value the same way a buggy host implementation would, making
 * the check meaningless. Everything else (headers, signature, kind, job
 * class, argument names) is parsed normally.
 *
 * A `kind=job` response is held open for `ATOMS_TEST_JOB_DELAY_MS` and the
 * moment it is finally sent is recorded on the record as `respondedAt`. That
 * delay is what makes checks 16/17 able to tell an AWAITED delivery from an
 * orphaned one: asserting the listener merely RECEIVED the request proves only
 * that the POST was started, which a fire-and-forget implementation that never
 * awaits anything also satisfies. Comparing "job response sent" against "invoke
 * response received" is the observable that distinguishes them, and orphaned
 * deliveries are the failure that passes locally and silently drops jobs on
 * deployed workerd (mvp-spec.md §Appendix item 4).
 */
class CallbackListener {
    constructor(publicKeyB64) {
        this.publicKey = importRawEd25519PublicKey(publicKeyB64);
        /** @type {object[]} */
        this.records = [];
        this.seenNonces = new Set();
        this.server = null;
    }

    clear() {
        this.records = [];
    }

    async start(port) {
        this.server = createServer((req, res) => this.onRequest(req, res));
        await new Promise((resolve, reject) => {
            this.server.once('error', reject);
            this.server.listen(port, '127.0.0.1', () => resolve());
        });
    }

    async stop() {
        if (!this.server) return;
        const server = this.server;
        this.server = null;
        await new Promise((resolve) => {
            server.closeAllConnections?.();
            server.close(() => resolve());
            setTimeout(resolve, 2000).unref();
        });
    }

    onRequest(req, res) {
        const chunks = [];
        req.on('data', (c) => chunks.push(c));
        req.on('end', () => {
            try {
                this.handle(req, res, Buffer.concat(chunks));
            } catch (e) {
                try {
                    res.writeHead(500, { 'content-type': 'application/json' });
                    res.end(JSON.stringify({ error: { code: 'internal', message: String(e) } }));
                } catch {
                    /* the socket may already be gone */
                }
            }
        });
    }

    handle(req, res, rawBody) {
        if (req.method !== 'POST' || req.url !== '/atoms/callback') {
            res.writeHead(404);
            res.end();
            return;
        }

        const timestamp = String(req.headers['x-atoms-timestamp'] ?? '');
        const nonce = String(req.headers['x-atoms-nonce'] ?? '');
        const signatureB64 = String(req.headers['x-atoms-signature'] ?? '');
        const kind = String(req.headers['x-atoms-kind'] ?? '');

        const message = Buffer.concat([Buffer.from(`v1\n${timestamp}\n${nonce}\n`, 'utf8'), rawBody]);
        let signatureValid = false;
        try {
            signatureValid = verifyEd25519(null, message, this.publicKey, Buffer.from(signatureB64, 'base64'));
        } catch {
            signatureValid = false;
        }

        const timestampFresh = /^-?\d+$/.test(timestamp) && Math.abs(Date.now() / 1000 - Number(timestamp)) <= 300;
        const nonceValid = /^[0-9a-f]{32}$/.test(nonce);
        const nonceRepeated = nonce !== '' && this.seenNonces.has(nonce);
        if (nonce !== '') this.seenNonces.add(nonce);

        const rawText = rawBody.toString('utf8');
        let parsed = null;
        try {
            parsed = JSON.parse(rawText);
        } catch {
            /* left null — a malformed body is still recorded for the check to see */
        }

        const record = {
            kind,
            headers: { timestamp, nonce, signatureB64 },
            rawText,
            parsed,
            signatureValid,
            timestampFresh,
            nonceValid,
            nonceRepeated,
            receivedAt: Date.now(),
            /** Set when this request's response has actually been written. */
            respondedAt: null,
        };
        this.records.push(record);

        /** Send a response and stamp the moment it went out. */
        const respond = (status, body) => {
            record.respondedAt = Date.now();
            res.writeHead(status, { 'content-type': 'application/json' });
            res.end(body);
        };

        if (kind === 'methods') {
            const method = parsed?.method;
            if (method === 'stall') {
                // Never respond: the client's AbortSignal.timeout() is what ends
                // this exchange (conformance check 15).
                return;
            }
            if (method === 'echoBig') {
                // Opaque-body invariant: extract the wide integer TEXTUALLY.
                const m = /"args"\s*:\s*\[\s*(-?\d+)\s*\]/.exec(rawText);
                const literal = m ? m[1] : '0';
                respond(200, `{"result":${literal}}`);
                return;
            }
            respond(
                422,
                JSON.stringify({ error: { code: 'ATOMS-E066', message: `no fixture handler for method ${method}` } })
            );
            return;
        }

        if (kind === 'job') {
            // Deliberately slow — see the class docblock. The Worker's turn
            // response must not come back before this does.
            setTimeout(() => {
                try {
                    respond(200, '{"queued":true}');
                } catch {
                    /* the socket may already be gone */
                }
            }, JOB_DELAY_MS).unref();
            return;
        }

        respond(
            422,
            JSON.stringify({ error: { code: 'invalid_request', message: `unknown X-Atoms-Kind ${JSON.stringify(kind)}` } })
        );
    }
}

/**
 * Load the per-run key file scripts/dev-with-callback.mjs wrote. Absent means
 * the suite is running against a Worker with no callback channel configured
 * (or against a deployed Worker) — checks 13-17 skip rather than fail.
 */
function loadCallbackKeyFile() {
    const path = join(__dirname, '.callback-key.json');
    if (!existsSync(path)) return null;
    try {
        return JSON.parse(readFileSync(path, 'utf-8'));
    } catch (e) {
        console.error(`Warning: could not read ${path}: ${e.message}`);
        return null;
    }
}

/** Set inside run(), before the check loop, once the listener (if any) is up. */
let listener = null;

/**
 * Set by CHECK 28, the merged PDO differential report (all groups, one
 * object) — {@see M1 design §5.4}. CHECK 30 re-uses this rather than
 * re-running the differential matrix a second time, the same way the
 * callback listener's records are reused across checks 13-17.
 * @type {{php: string, cases: Array<{id: string, group: string, member: string, title: string, class: string, ours: string, theirs: string, detail: string}>}|null}
 */
let pdoMatrixReport = null;

// ---------------------------------------------------------------- checks

const checks = [];

// CHECK 1: healthz
checks.push(async () => {
    const { status, data } = await request('GET', '/healthz');
    const checkNum = 1;
    const name = 'healthz';

    if (status === 200 && data?.ok === true) {
        pass(checkNum, name);
    } else {
        fail(checkNum, name, `status=${status}, ok=${data?.ok}`);
    }
});

// CHECK 2: invoke + result envelope
checks.push(async () => {
    const checkNum = 2;
    const name = 'invoke + result envelope';
    const id = atomId('envelope');

    const { status, data } = await invoke('Counter', id, 'increment', [1]);

    if (status !== 200) {
        fail(checkNum, name, `status=${status} body=${JSON.stringify(data)}`);
        return;
    }
    if (data?.error) {
        fail(checkNum, name, `error: ${data.error.code} — ${data.error.message}`);
        return;
    }
    if (data.result !== 1) {
        fail(checkNum, name, `result=${JSON.stringify(data.result)} (expected 1)`);
        return;
    }
    if (data.atom?.type !== 'Counter' || data.atom?.id !== id) {
        fail(checkNum, name, `malformed atom: ${JSON.stringify(data.atom)}`);
        return;
    }

    // An unknown method must come back as method_not_found, not as a 200.
    const missing = await invoke('Counter', id, 'noSuchMethod', []);
    if (missing.status !== 404 || missing.data?.error?.code !== 'method_not_found') {
        fail(
            checkNum,
            name,
            `unknown method gave ${missing.status}/${missing.data?.error?.code} (expected 404/method_not_found)`
        );
        return;
    }

    // An unknown atom type must be refused before any DO is touched.
    const unknownType = await invoke('NotAnAtom', id, 'increment', [1]);
    if (unknownType.status !== 404 || unknownType.data?.error?.code !== 'unknown_atom_type') {
        fail(
            checkNum,
            name,
            `unknown type gave ${unknownType.status}/${unknownType.data?.error?.code} (expected 404/unknown_atom_type)`
        );
        return;
    }

    pass(checkNum, name, `result=${data.result}, method_not_found + unknown_atom_type mapped`);
});

// CHECK 3: warm-residency (in-memory counter)
checks.push(async () => {
    const checkNum = 3;
    const name = 'warm-residency (in-memory counter)';
    const id = atomId('warm');

    const r1 = await invoke('Counter', id, 'increment', [5]);
    const r2 = await invoke('Counter', id, 'increment', [3]);
    const r3 = await invoke('Counter', id, 'getStats', []);

    for (const [label, r] of [['increment#1', r1], ['increment#2', r2], ['getStats', r3]]) {
        if (r.status !== 200 || r.data?.error) {
            fail(checkNum, name, `${label} failed: ${r.status} ${JSON.stringify(r.data?.error)}`);
            return;
        }
    }

    const stats = r3.data.result;

    if (r1.data.result !== 5 || r2.data.result !== 8) {
        fail(checkNum, name, `durable value wrong: ${r1.data.result}, ${r2.data.result} (expected 5, 8)`);
        return;
    }
    if (stats?.turnsThisResidency !== 3) {
        fail(
            checkNum,
            name,
            `turnsThisResidency=${stats?.turnsThisResidency} (expected 3) — in-memory state did not survive`
        );
        return;
    }
    if (stats?.currentValue !== 8) {
        fail(checkNum, name, `currentValue=${stats?.currentValue} (expected 8)`);
        return;
    }

    pass(checkNum, name, `3 turns on one residency, value=${stats.currentValue}`);
});

// CHECK 4: isolation between two IDs
checks.push(async () => {
    const checkNum = 4;
    const name = 'isolation between two IDs';
    const a = atomId('iso-a');
    const b = atomId('iso-b');

    const r1 = await invoke('Counter', a, 'increment', [10]);
    const r2 = await invoke('Counter', b, 'increment', [20]);

    if (r1.status !== 200 || r2.status !== 200) {
        fail(checkNum, name, `invoke failed: ${r1.status}/${r2.status}`);
        return;
    }

    // Each id must see only its own writes, and its own in-memory state.
    const s1 = await invoke('Counter', a, 'getStats', []);
    const s2 = await invoke('Counter', b, 'getStats', []);

    if (r1.data.result !== 10 || r2.data.result !== 20) {
        fail(checkNum, name, `isolation broken: a=${r1.data.result} (10), b=${r2.data.result} (20)`);
        return;
    }
    if (s1.data.result?.currentValue !== 10 || s2.data.result?.currentValue !== 20) {
        fail(
            checkNum,
            name,
            `durable state leaked: a=${s1.data.result?.currentValue}, b=${s2.data.result?.currentValue}`
        );
        return;
    }

    pass(checkNum, name, `isolated: ${a}=10, ${b}=20`);
});

// CHECK 5: migrations applied once, user_version correct, activation row present
checks.push(async () => {
    const checkNum = 5;
    const name = 'migrations applied once, user_version correct, activation row';
    const id = atomId('mig');

    const first = await invoke('Counter', id, 'getValue', []);
    if (first.status !== 200 || first.data?.error) {
        fail(checkNum, name, `getValue failed: ${JSON.stringify(first.data)}`);
        return;
    }
    if (first.data.result !== 0) {
        fail(checkNum, name, `fresh counter is ${first.data.result} (expected 0 from 001_init.sql)`);
        return;
    }

    // Counter ships two migrations, so head version is 2.
    const info = await debugInfo('Counter', id);
    if (info.user_version !== 2) {
        fail(checkNum, name, `user_version=${info.user_version} (expected 2)`);
        return;
    }

    // 002_add_stats.sql must have run too — its table has to exist.
    const stats = await invoke('Counter', id, 'getStats', []);
    if (stats.status !== 200 || stats.data?.error) {
        fail(checkNum, name, `getStats failed after migrations: ${JSON.stringify(stats.data)}`);
        return;
    }

    // onActivation() wrote exactly one row for this residency.
    const activations = await invoke('Counter', id, 'getActivations', []);
    if (activations.data?.result !== 1) {
        fail(checkNum, name, `activation rows=${activations.data?.result} (expected 1)`);
        return;
    }

    // A second turn must not re-run migrations: user_version stays at head and
    // no CREATE TABLE is replayed (which would surface as a sql_error).
    const again = await invoke('Counter', id, 'getValue', []);
    const info2 = await debugInfo('Counter', id);
    if (again.status !== 200 || again.data?.error || info2.user_version !== 2) {
        fail(checkNum, name, `re-run: status=${again.status}, user_version=${info2.user_version}`);
        return;
    }

    pass(checkNum, name, `user_version=2, 1 activation row, no replay`);
});

// CHECK 6: tx commit read-your-own-write
checks.push(async () => {
    const checkNum = 6;
    const name = 'tx commit read-your-own-write';
    const id = atomId('tx-commit');

    // The write is read back from INSIDE the open transaction.
    const ryow = await invoke('Vault', id, 'putAndReadInTransaction', ['ryow', 4242]);
    if (ryow.status !== 200 || ryow.data?.error) {
        fail(checkNum, name, `putAndReadInTransaction failed: ${JSON.stringify(ryow.data)}`);
        return;
    }
    if (Number(parseInt64(ryow.data.result)) !== 4242) {
        fail(checkNum, name, `in-transaction read saw ${ryow.data.result} (expected 4242)`);
        return;
    }

    // ...and it is still there after the transaction committed.
    const after = await invoke('Vault', id, 'getBig', ['ryow']);
    if (Number(parseInt64(after.data?.result)) !== 4242) {
        fail(checkNum, name, `committed value is ${after.data?.result} (expected 4242)`);
        return;
    }

    // A multi-statement transaction commits as a unit.
    await invoke('Vault', id, 'putBig', ['key1', 100]);
    await invoke('Vault', id, 'putBig', ['key2', 0]);

    const transfer = await invoke('Vault', id, 'transfer', ['key1', 'key2', 30, false]);
    if (transfer.status !== 200 || transfer.data?.error || transfer.data.result !== true) {
        fail(checkNum, name, `transfer failed: ${JSON.stringify(transfer.data)}`);
        return;
    }

    const k1 = parseInt64((await invoke('Vault', id, 'getBig', ['key1'])).data?.result);
    const k2 = parseInt64((await invoke('Vault', id, 'getBig', ['key2'])).data?.result);

    if (k1 === 70n && k2 === 30n) {
        pass(checkNum, name, `read-your-own-write inside tx, transfer committed (70/30)`);
    } else {
        fail(checkNum, name, `key1=${k1} (expected 70), key2=${k2} (expected 30)`);
    }
});

// CHECK 7: tx rollback discards observed write
checks.push(async () => {
    const checkNum = 7;
    const name = 'tx rollback discards observed write';
    const id = atomId('tx-rollback');

    // A write that was genuinely read back from inside the open transaction
    // must still vanish when that transaction rolls back.
    const observed = await invoke('Vault', id, 'putReadThenFail', ['ghost', 99]);
    if (observed.status !== 200 || observed.data?.error) {
        fail(checkNum, name, `putReadThenFail failed: ${JSON.stringify(observed.data)}`);
        return;
    }
    if (observed.data.result?.observed !== 99 || observed.data.result?.rolledBack !== true) {
        fail(checkNum, name, `in-transaction read/rollback: ${JSON.stringify(observed.data.result)}`);
        return;
    }
    const ghost = await invoke('Vault', id, 'getBig', ['ghost']);
    if (parseInt64(ghost.data?.result) !== 0n) {
        fail(checkNum, name, `the observed write survived the rollback: ghost=${ghost.data?.result}`);
        return;
    }

    await invoke('Vault', id, 'putBig', ['a', 500]);
    await invoke('Vault', id, 'putBig', ['b', 200]);

    const rolled = await invoke('Vault', id, 'transfer', ['a', 'b', 100, true]);
    if (rolled.status !== 200 || rolled.data?.error) {
        fail(checkNum, name, `transfer call failed: ${JSON.stringify(rolled.data)}`);
        return;
    }
    if (rolled.data.result !== false) {
        fail(checkNum, name, `transfer reported ${rolled.data.result} (expected false — it rolled back)`);
        return;
    }

    const a = parseInt64((await invoke('Vault', id, 'getBig', ['a'])).data?.result);
    const b = parseInt64((await invoke('Vault', id, 'getBig', ['b'])).data?.result);

    if (a !== 500n || b !== 200n) {
        fail(checkNum, name, `rollback did not discard the write set: a=${a} (500), b=${b} (200)`);
        return;
    }

    // A turn that ends with a transaction still open — a forgotten commit(),
    // which the host cannot see — is an application bug: it must roll back and
    // report atom_exception, not destroy the residency (which, being
    // deterministic, every retry would destroy again).
    const leaked = await invoke('Vault', id, 'leakTransaction', ['leaked', 1234]);
    if (leaked.status !== 500 || leaked.data?.error?.code !== 'atom_exception') {
        fail(
            checkNum,
            name,
            `an abandoned transaction gave ${leaked.status}/${leaked.data?.error?.code} ` +
                `(expected 500/atom_exception): ${JSON.stringify(leaked.data)}`
        );
        return;
    }
    const leakedValue = await invoke('Vault', id, 'getBig', ['leaked']);
    if (leakedValue.status !== 200 || parseInt64(leakedValue.data?.result) !== 0n) {
        fail(
            checkNum,
            name,
            `the abandoned transaction's write was kept: ${JSON.stringify(leakedValue.data)}`
        );
        return;
    }

    // The Atom is still usable, and a later transaction still commits.
    const after = await invoke('Vault', id, 'putAndReadInTransaction', ['after-rollback', 7]);
    if (after.status !== 200 || Number(parseInt64(after.data?.result)) !== 7) {
        fail(checkNum, name, `transaction machine wedged after rollback: ${JSON.stringify(after.data)}`);
        return;
    }

    pass(checkNum, name, `write set discarded, abandoned tx rolled back, next transaction still commits`);
});

// CHECK 8: uncaught exception → atom_exception envelope, next turn healthy
checks.push(async () => {
    const checkNum = 8;
    const name = 'uncaught exception → atom_exception, next turn healthy';
    const id = atomId('exc');

    await invoke('Counter', id, 'increment', [4]);

    const boom = await invoke('Counter', id, 'boom', ['fixture explosion']);

    if (boom.status !== 500) {
        fail(checkNum, name, `status=${boom.status} (expected 500), body=${JSON.stringify(boom.data)}`);
        return;
    }
    if (boom.data?.error?.code !== 'atom_exception') {
        fail(checkNum, name, `code=${boom.data?.error?.code} (expected atom_exception)`);
        return;
    }
    if (!String(boom.data.error.message).includes('fixture explosion')) {
        fail(checkNum, name, `message did not carry the throwable: ${boom.data.error.message}`);
        return;
    }
    if (boom.data.error.class !== 'RuntimeException') {
        fail(checkNum, name, `class=${JSON.stringify(boom.data.error.class)} (expected RuntimeException)`);
        return;
    }

    // Same residency must survive: in-memory state intact, durable state intact.
    const stats = await invoke('Counter', id, 'getStats', []);
    if (stats.status !== 200 || stats.data?.error) {
        fail(checkNum, name, `next turn failed: ${JSON.stringify(stats.data)}`);
        return;
    }
    if (stats.data.result?.currentValue !== 4) {
        fail(checkNum, name, `durable value=${stats.data.result?.currentValue} (expected 4)`);
        return;
    }
    if (stats.data.result?.turnsThisResidency !== 3) {
        fail(
            checkNum,
            name,
            `turnsThisResidency=${stats.data.result?.turnsThisResidency} (expected 3) — residency was recycled`
        );
        return;
    }

    pass(checkNum, name, `atom_exception envelope, residency intact`);
});

// CHECK 9: int64 matrix (±2^31, ±2^53, ±(2^63−1))
checks.push(async () => {
    const checkNum = 9;
    const name = 'int64 matrix';
    const id = atomId('int64');

    const cases = [
        { label: '2^31-1', val: 2147483647n },
        { label: '-2^31', val: -2147483648n },
        { label: '2^53-1', val: 9007199254740991n },
        { label: '-(2^53-1)', val: -9007199254740991n },
        { label: '2^63-1', val: 9223372036854775807n },
        { label: '-(2^63-1)', val: -9223372036854775807n },
    ];

    const problems = [];

    for (const tc of cases) {
        // args -> SQL -> results
        const put = await invoke('Vault', id, 'putBig', [tc.label, wire(tc.val)]);
        if (put.status !== 200 || put.data?.error) {
            problems.push(`${tc.label}: putBig ${JSON.stringify(put.data?.error ?? put.status)}`);
            continue;
        }

        const got = await invoke('Vault', id, 'getBig', [tc.label]);
        if (got.status !== 200 || got.data?.error) {
            problems.push(`${tc.label}: getBig ${JSON.stringify(got.data?.error ?? got.status)}`);
            continue;
        }

        const round = parseInt64(got.data.result);
        if (round !== tc.val) {
            problems.push(`${tc.label}: round-trip ${round} !== ${tc.val}`);
            continue;
        }

        // Values that need the tag must actually carry it, and values that do
        // not must stay plain JSON numbers.
        const needsTag = tc.val > 9007199254740991n || tc.val < -9007199254740991n;
        if (needsTag !== isInt64Tag(got.data.result)) {
            problems.push(
                `${tc.label}: tagged=${isInt64Tag(got.data.result)} but expected ${needsTag}`
            );
            continue;
        }

        // lastInsertId leg: insert through db()->pdo() and read the row back.
        const rowid = await invoke('Vault', id, 'appendLedger', [tc.label, wire(tc.val)]);
        if (rowid.status !== 200 || rowid.data?.error) {
            problems.push(`${tc.label}: appendLedger ${JSON.stringify(rowid.data?.error ?? rowid.status)}`);
            continue;
        }
        const rid = Number(parseInt64(rowid.data.result));
        if (!Number.isInteger(rid) || rid < 1) {
            problems.push(`${tc.label}: lastInsertId=${JSON.stringify(rowid.data.result)}`);
            continue;
        }

        const row = await invoke('Vault', id, 'readLedger', [rid]);
        if (row.status !== 200 || row.data?.error) {
            problems.push(`${tc.label}: readLedger ${JSON.stringify(row.data?.error ?? row.status)}`);
            continue;
        }
        if (parseInt64(row.data.result?.value) !== tc.val) {
            problems.push(`${tc.label}: ledger value ${JSON.stringify(row.data.result?.value)}`);
        }

        // ...and lastInsertId must survive the reads that follow the INSERT.
        // PDO/SQLite hold it until the next successful insert; a host that
        // reports a rowid of 0 for every non-writing statement silently turns
        // `INSERT parent; SELECT ...; INSERT child(parent_id=lastInsertId())`
        // into a table full of zeroes.
        const held = await invoke('Vault', id, 'appendLedgerThenRead', [tc.label, wire(tc.val)]);
        if (held.status !== 200 || held.data?.error) {
            problems.push(`${tc.label}: appendLedgerThenRead ${JSON.stringify(held.data?.error ?? held.status)}`);
            continue;
        }
        const { immediate, afterRead } = held.data.result ?? {};
        if (!Number.isInteger(immediate) || immediate < 1) {
            problems.push(`${tc.label}: lastInsertId right after INSERT was ${JSON.stringify(immediate)}`);
        } else if (afterRead !== immediate) {
            problems.push(
                `${tc.label}: lastInsertId was ${immediate} after the INSERT but ${JSON.stringify(afterRead)} ` +
                    'after an intervening read — a read must not reset it'
            );
        }
    }

    // The same rule for the other direction of the same door: args nested past
    // what the guest's json_decode() will follow must be a client error, and
    // must never leave the guest unable to read its own turn envelope (which
    // used to throw out of the parked loop and poison the residency).
    {
        // Hand-built body: the `request()` helper serializes objects, and this
        // one has to be raw text to be deeper than JSON.stringify would go.
        let nested = 'null';
        for (let i = 0; i < 600; i++) nested = `[${nested}]`;
        const res = await fetch(new URL(`/invoke/Vault/${id}/putBig`, baseUrl).toString(), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                ...(APP_KEY ? { Authorization: `Bearer ${APP_KEY}` } : {}),
            },
            body: `{"args":[${nested}]}`,
        });
        const body = await res.text();
        if (res.status < 400) {
            problems.push(`deeply nested args were accepted (${res.status})`);
        } else if (res.status >= 500) {
            problems.push(
                `deeply nested args produced ${res.status} ${body.slice(0, 120)} — ` +
                    'a malformed argument must be a client error, not a runtime failure'
            );
        }
    }

    // ...and a RETURN value the boundary cannot carry is a typed turn error,
    // not a dead residency either.
    const deepReturn = await invoke('Vault', id, 'returnDeeplyNested', [600]);
    if (deepReturn.status !== 500 || deepReturn.data?.error?.code !== 'atom_exception') {
        problems.push(
            `an unencodable return value gave ${deepReturn.status}/${deepReturn.data?.error?.code} ` +
                '(expected 500/atom_exception)'
        );
    }

    // A tagged value outside int64 range is an error, never a truncation —
    // and never a dead residency. Client-supplied args reach the guest
    // untouched, so a bad tag must be refused as a bad *request*, with the
    // Atom still serving turns afterwards.
    for (const bad of ['9223372036854775808', '-9223372036854775809', 'not-a-number', '1.5']) {
        const overflow = await invoke('Vault', id, 'putBig', ['overflow', { $atoms_int64: bad }]);

        if (overflow.status === 200 && !overflow.data?.error) {
            problems.push(`the out-of-range tag ${bad} was accepted instead of refused`);
            continue;
        }
        if (overflow.status >= 500) {
            problems.push(
                `the tag ${bad} produced ${overflow.status}/${overflow.data?.error?.code} — ` +
                    'a malformed argument must be a client error, not a runtime failure'
            );
        }
    }

    // ...and the residency is still alive and still holds its state.
    const survived = await invoke('Vault', id, 'getBig', ['2^63-1']);
    if (survived.status !== 200 || parseInt64(survived.data?.result) !== 9223372036854775807n) {
        problems.push(
            `the Atom did not survive the malformed tags: ${JSON.stringify(survived.data)}`
        );
    }

    if (problems.length === 0) {
        pass(checkNum, name, `${cases.length} cases through args/SQL/results/lastInsertId`);
    } else {
        fail(checkNum, name, problems.join('; '));
    }
});

// CHECK 10: reserved-table rejection
checks.push(async () => {
    const checkNum = 10;
    const name = 'reserved-table rejection';
    const id = atomId('reserved');

    const r = await invoke('Counter', id, 'readReserved', []);

    if (r.status !== 200 || r.data?.error) {
        fail(checkNum, name, `readReserved failed: ${JSON.stringify(r.data)}`);
        return;
    }
    if (r.data.result?.rejected !== true) {
        fail(checkNum, name, `customer SQL reached __atoms_meta: ${JSON.stringify(r.data.result)}`);
        return;
    }
    if (!String(r.data.result.message).includes('reserved_table')) {
        fail(checkNum, name, `rejected, but not as reserved_table: ${r.data.result.message}`);
        return;
    }

    // The guard is lexical, so it must agree with SQLite's tokenizer about what
    // is a literal: an apostrophe inside a `--` comment is not one. Getting this
    // wrong lets a second statement through and lands `__atoms_meta.atom_type`
    // in customer hands, which 409s the Atom forever.
    const viaComment = await invoke('Counter', id, 'readReservedViaComment', []);
    if (viaComment.status !== 200 || viaComment.data?.error) {
        fail(checkNum, name, `readReservedViaComment failed: ${JSON.stringify(viaComment.data)}`);
        return;
    }
    if (viaComment.data.result?.rejected !== true) {
        fail(
            checkNum,
            name,
            'customer SQL reached __atoms_meta through an apostrophe in a comment: ' +
                JSON.stringify(viaComment.data.result)
        );
        return;
    }

    // The identity the smuggled UPDATE aimed at must be untouched.
    const identity = await debugInfo('Counter', id);
    if (identity.stored?.type !== 'Counter') {
        fail(checkNum, name, `__atoms_meta.atom_type is now ${JSON.stringify(identity.stored?.type)}`);
        return;
    }

    // The residency must survive a rejected statement.
    const after = await invoke('Counter', id, 'getValue', []);
    if (after.status !== 200 || after.data?.error) {
        fail(checkNum, name, `residency broken after rejection: ${JSON.stringify(after.data)}`);
        return;
    }

    pass(checkNum, name, `__atoms_meta refused (plain and via comment), residency healthy`);
});

// CHECK 11: turn serialization
checks.push(async () => {
    const checkNum = 11;
    const name = 'turn serialization';
    const id = atomId('serial');

    // Two concurrent invokes of a deliberately slow method. Because a turn is
    // one synchronous run of the guest, they must interleave nowhere: the
    // read-modify-write inside slowIncrement cannot be observed twice.
    const [r1, r2] = await Promise.all([
        invoke('Counter', id, 'slowIncrement', [1, 100]),
        invoke('Counter', id, 'slowIncrement', [1, 100]),
    ]);

    if (r1.status !== 200 || r2.status !== 200 || r1.data?.error || r2.data?.error) {
        fail(checkNum, name, `slowIncrement failed: ${JSON.stringify(r1.data)} / ${JSON.stringify(r2.data)}`);
        return;
    }

    const seen = [r1.data.result, r2.data.result].sort((a, b) => a - b);
    if (seen[0] !== 1 || seen[1] !== 2) {
        fail(checkNum, name, `not serialized: results were ${JSON.stringify(seen)} (expected [1,2])`);
        return;
    }

    const stats = await invoke('Counter', id, 'getStats', []);
    if (stats.data.result?.currentValue !== 2 || stats.data.result?.turnsThisResidency !== 3) {
        fail(
            checkNum,
            name,
            `value=${stats.data.result?.currentValue} (2), turns=${stats.data.result?.turnsThisResidency} (3)`
        );
        return;
    }

    pass(checkNum, name, `two concurrent turns serialized: [1,2]`);
});

// CHECK 12: eviction/wake
checks.push(async () => {
    const checkNum = 12;
    const name = 'eviction/wake';
    const id = atomId('evict');

    const first = await invoke('Counter', id, 'increment', [7]);
    if (first.status !== 200 || first.data?.error) {
        fail(checkNum, name, `initial invoke failed: ${JSON.stringify(first.data)}`);
        return;
    }

    const before = await invoke('Counter', id, 'getStats', []);
    const beforeInfo = await debugInfo('Counter', id);

    if (before.data.result?.turnsThisResidency !== 2) {
        fail(checkNum, name, `pre-eviction turns=${before.data.result?.turnsThisResidency} (expected 2)`);
        return;
    }

    console.log(`   (waiting ${EVICTION_WAIT_MS}ms for eviction...)`);
    await new Promise((r) => setTimeout(r, EVICTION_WAIT_MS));

    const afterInfo = await debugInfo('Counter', id);
    const after = await invoke('Counter', id, 'getStats', []);

    if (after.status !== 200 || after.data?.error) {
        fail(checkNum, name, `post-eviction invoke failed: ${JSON.stringify(after.data)}`);
        return;
    }

    const stats = after.data.result;

    // 1. the residency was really rebuilt
    if (!(afterInfo.constructions > beforeInfo.constructions)) {
        fail(
            checkNum,
            name,
            `constructions did not increase (${beforeInfo.constructions} -> ${afterInfo.constructions}): ` +
                'the Durable Object was never evicted, so nothing was proved'
        );
        return;
    }
    // 2. in-memory state reset
    if (stats?.turnsThisResidency !== 1) {
        fail(checkNum, name, `turnsThisResidency=${stats?.turnsThisResidency} (expected 1 after wake)`);
        return;
    }
    // 3. durable state intact
    if (stats?.currentValue !== 7) {
        fail(checkNum, name, `durable value=${stats?.currentValue} (expected 7)`);
        return;
    }
    // 4. onActivation ran again for the new residency
    if (stats?.activations !== 2) {
        fail(checkNum, name, `activation rows=${stats?.activations} (expected 2 — onActivation must re-run)`);
        return;
    }

    pass(
        checkNum,
        name,
        `constructions ${beforeInfo.constructions} -> ${afterInfo.constructions}, memory reset, value=7, onActivation re-ran`
    );
});

// CHECK 13: app() round trip, int64-exact
checks.push(async () => {
    const checkNum = 13;
    const name = 'app() round trip, int64-exact';
    if (!listener) {
        skip(
            checkNum,
            name,
            'no callback listener — test/.callback-key.json is missing; run via `npm run dev:callback`',
            REQUIRE_CALLBACK_CHECKS,
            'ATOMS_CALLBACK_PORT'
        );
        return;
    }

    const id = atomId('app-echo');
    const cases = [
        { label: '0', val: 0n },
        { label: '2^31', val: 2147483648n },
        { label: '-2^31', val: -2147483648n },
        { label: '2^53-1', val: 9007199254740991n },
        { label: '2^63-1', val: 9223372036854775807n },
        { label: '-(2^63-1)', val: -9223372036854775807n },
    ];

    const problems = [];
    for (const tc of cases) {
        const before = listener.records.length;
        const res = await invoke('Vault', id, 'echoViaApp', [wire(tc.val)]);
        if (res.status !== 200 || res.data?.error) {
            problems.push(`${tc.label}: invoke ${res.status} ${JSON.stringify(res.data?.error ?? res.data)}`);
            continue;
        }
        const got = parseInt64(res.data.result);
        if (got !== tc.val) {
            problems.push(`${tc.label}: round-trip ${got} !== ${tc.val}`);
        }

        const made = listener.records.slice(before);
        if (made.length !== 1) {
            problems.push(`${tc.label}: listener recorded ${made.length} requests (expected 1)`);
            continue;
        }
        const rec = made[0];
        if (rec.kind !== 'methods') problems.push(`${tc.label}: X-Atoms-Kind=${rec.kind} (expected methods)`);
        if (!rec.signatureValid) problems.push(`${tc.label}: signature did not verify`);
        if (!rec.timestampFresh) problems.push(`${tc.label}: timestamp not within +-300s`);
        if (!rec.nonceValid) problems.push(`${tc.label}: nonce ${JSON.stringify(rec.headers.nonce)} is not 32 lowercase hex`);
        if (rec.nonceRepeated) problems.push(`${tc.label}: nonce repeated`);
        if (rec.parsed?.atom?.type !== 'Vault' || rec.parsed?.atom?.id !== id || rec.parsed?.method !== 'echoBig') {
            problems.push(`${tc.label}: body mismatch ${JSON.stringify(rec.parsed)}`);
        }
    }

    if (problems.length === 0) {
        pass(checkNum, name, `${cases.length} int64 boundary values round-tripped through app(), signed, no nonce reuse`);
    } else {
        fail(checkNum, name, problems.join('; '));
    }
});

// CHECK 14: app() rejected inside a transaction
checks.push(async () => {
    const checkNum = 14;
    const name = 'app() rejected inside a transaction';
    if (!listener) {
        skip(checkNum, name, 'no callback listener', REQUIRE_CALLBACK_CHECKS, 'ATOMS_CALLBACK_PORT');
        return;
    }

    const id = atomId('app-tx');
    listener.clear();

    const res = await invoke('Vault', id, 'appInsideTransaction', []);
    if (res.status !== 500 || res.data?.error?.code !== 'atom_exception') {
        fail(checkNum, name, `status=${res.status} code=${res.data?.error?.code} (expected 500/atom_exception)`);
        return;
    }
    if (!String(res.data.error.message).includes('ATOMS-E082')) {
        fail(checkNum, name, `message did not carry ATOMS-E082: ${res.data.error.message}`);
        return;
    }
    if (listener.records.length !== 0) {
        fail(
            checkNum,
            name,
            `listener saw ${listener.records.length} request(s) — the guest-side guard must fire before crossing`
        );
        return;
    }
    const row = await invoke('Vault', id, 'getBig', ['app-inside-tx']);
    if (parseInt64(row.data?.result) !== 0n) {
        fail(checkNum, name, `the open transaction's write survived: ${JSON.stringify(row.data)}`);
        return;
    }
    const after = await invoke('Vault', id, 'getBig', ['anything']);
    if (after.status !== 200 || after.data?.error) {
        fail(checkNum, name, `residency unhealthy after the rejection: ${JSON.stringify(after.data)}`);
        return;
    }

    pass(checkNum, name, `ATOMS-E082, no request left the Worker, write rolled back, residency healthy`);
});

// CHECK 15: deadline overrun (uncaught, and caught with the budget latched)
checks.push(async () => {
    const checkNum = 15;
    const name = 'deadline overrun (uncaught 504, caught + budget latched)';
    if (!listener) {
        skip(checkNum, name, 'no callback listener', REQUIRE_CALLBACK_CHECKS, 'ATOMS_CALLBACK_PORT');
        return;
    }
    if (!TURN_DEADLINE_MS) {
        skip(checkNum, name, 'ATOMS_TURN_DEADLINE_MS not set in the runner env — must match the value the Worker was started with');
        return;
    }

    const problems = [];

    // 15a — uncaught: the turn reports turn_deadline_exceeded, and the
    // residency stays healthy for the next invoke.
    const idA = atomId('deadline-uncaught');
    listener.clear();
    const t0 = Date.now();
    const stalled = await invoke('Vault', idA, 'stallViaApp', []);
    const elapsed = Date.now() - t0;

    if (stalled.status !== 504) problems.push(`15a: status=${stalled.status} (expected 504)`);
    if (stalled.data?.error?.code !== 'turn_deadline_exceeded') {
        problems.push(`15a: code=${stalled.data?.error?.code} (expected turn_deadline_exceeded)`);
    }
    if (stalled.data?.error?.retryable !== true) problems.push(`15a: retryable=${stalled.data?.error?.retryable} (expected true)`);
    if (elapsed < TURN_DEADLINE_MS) {
        problems.push(`15a: observed elapsed ${elapsed}ms is less than the configured deadline ${TURN_DEADLINE_MS}ms`);
    }
    // The upper bound is half the point: without it the check passes on a
    // Worker whose per-call abort is armed against a stale `remaining`, or
    // whose budget is not actually bounding anything and the turn ended because
    // ATOMS_CALLBACK_TIMEOUT_MS happened to fire. The slack covers wasm boot on
    // a cold residency plus a loaded runner, and nothing else.
    const DEADLINE_SLACK_MS = 3000;
    if (elapsed >= TURN_DEADLINE_MS + DEADLINE_SLACK_MS) {
        problems.push(
            `15a: observed elapsed ${elapsed}ms is not within ${DEADLINE_SLACK_MS}ms of the configured deadline ` +
                `${TURN_DEADLINE_MS}ms — the turn ran long past its budget`
        );
    }

    // Same Atom, same residency, next turn. Two invokes, in this order,
    // because they fail differently if the exhausted budget leaked out of the
    // turn that latched it: a turn that touches the callback channel at all
    // would come straight back as turn_deadline_exceeded on a budget it never
    // spent, while one that does not would still be fine — so the first proves
    // the residency lives, and the second proves the BUDGET was reset.
    const afterA = await invoke('Vault', idA, 'getBig', ['anything']);
    if (afterA.status !== 200 || afterA.data?.error) {
        problems.push(`15a: next invoke on the same Atom failed: ${JSON.stringify(afterA.data)}`);
    }
    const afterAppA = await invoke('Vault', idA, 'echoViaApp', [7]);
    if (afterAppA.status !== 200 || afterAppA.data?.error) {
        problems.push(
            `15a: a later app() on the same Atom failed: ${JSON.stringify(afterAppA.data)} — the exhausted turn ` +
                'budget leaked past the turn that latched it'
        );
    } else if (Number(afterAppA.data.result) !== 7) {
        problems.push(`15a: a later app() returned ${JSON.stringify(afterAppA.data.result)} (expected 7)`);
    }

    // 15b — caught, then a second app() call that fails immediately on the
    // latched budget: an ordinary 200, and exactly one request ever reached
    // the listener.
    const idB = atomId('deadline-caught');
    listener.clear();
    const caught = await invoke('Vault', idB, 'stallCaught', []);
    if (caught.status !== 200 || caught.data?.error) {
        problems.push(`15b: status=${caught.status} error=${JSON.stringify(caught.data?.error)} (expected 200)`);
    }
    if (caught.data?.result !== 'stall-caught-budget-latched') {
        problems.push(`15b: result=${JSON.stringify(caught.data?.result)} (expected 'stall-caught-budget-latched')`);
    }
    if (listener.records.length !== 1) {
        problems.push(
            `15b: listener recorded ${listener.records.length} requests (expected 1 — the second app() must not ` +
                're-reach the network once the budget is latched)'
        );
    }

    // The latch is PER TURN, not per residency. Nothing in 15b proves that on
    // its own: a budget that latched permanently would produce exactly the
    // same 200 and the same single request. The next turn on the SAME Atom
    // doing a successful app() is what tells the two apart.
    const afterB = await invoke('Vault', idB, 'echoViaApp', [11]);
    if (afterB.status !== 200 || afterB.data?.error) {
        problems.push(
            `15b: the next turn's app() failed: ${JSON.stringify(afterB.data)} — the latch outlived its turn`
        );
    } else if (Number(afterB.data.result) !== 11) {
        problems.push(`15b: the next turn's app() returned ${JSON.stringify(afterB.data.result)} (expected 11)`);
    }
    if (listener.records.length !== 2) {
        problems.push(
            `15b: listener recorded ${listener.records.length} requests after the follow-up (expected 2 — the ` +
                'next turn must reach the network again)'
        );
    }

    if (problems.length === 0) {
        pass(
            checkNum,
            name,
            `15a: 504/turn_deadline_exceeded after ${elapsed}ms (within ${DEADLINE_SLACK_MS}ms of the budget), ` +
                'residency healthy and a later app() still works; 15b: 200 with 1 request, latch released next turn'
        );
    } else {
        fail(checkNum, name, problems.join('; '));
    }
});

// CHECK 16: dispatch() delivered, signed, kind=job — AWAITED, not merely
// started; and the same from onActivation(), which runs before any turn exists.
checks.push(async () => {
    const checkNum = 16;
    const name = 'dispatch() awaited before the response, signed, kind=job — from a turn and from onActivation()';
    if (!listener) {
        skip(checkNum, name, 'no callback listener', REQUIRE_CALLBACK_CHECKS, 'ATOMS_CALLBACK_PORT');
        return;
    }

    const id = atomId('notify');
    listener.clear();

    const res = await invoke('Counter', id, 'notify', ['hello-16']);
    const invokeDoneAt = Date.now();
    if (res.status !== 200 || res.data?.error) {
        fail(checkNum, name, `notify failed: ${JSON.stringify(res.data)}`);
        return;
    }
    if (res.data.result !== 'notified:hello-16') {
        fail(checkNum, name, `the Atom's own response was affected: ${JSON.stringify(res.data.result)}`);
        return;
    }
    if (listener.records.length !== 1) {
        fail(checkNum, name, `listener recorded ${listener.records.length} requests (expected 1, delivered before the response)`);
        return;
    }
    const rec = listener.records[0];
    if (rec.kind !== 'job') {
        fail(checkNum, name, `X-Atoms-Kind=${rec.kind} (expected job)`);
        return;
    }
    if (!rec.signatureValid) {
        fail(checkNum, name, 'signature did not verify');
        return;
    }
    if (rec.parsed?.job !== 'App\\Jobs\\Notify') {
        fail(checkNum, name, `job=${JSON.stringify(rec.parsed?.job)} (expected App\\Jobs\\Notify)`);
        return;
    }
    if (rec.parsed?.args?.atomId !== id || rec.parsed?.args?.note !== 'hello-16') {
        fail(checkNum, name, `args=${JSON.stringify(rec.parsed?.args)} did not match the promoted constructor properties`);
        return;
    }
    // AWAITED, not merely started. The listener held this response open for
    // ATOMS_TEST_JOB_DELAY_MS; if the turn's 200 came back before the job
    // response was sent, the Worker returned without awaiting the delivery —
    // which works locally and silently drops jobs on deployed workerd
    // (mvp-spec.md §Appendix item 4). Receipt alone proves nothing here.
    if (rec.respondedAt === null) {
        fail(checkNum, name, 'the listener never finished responding to the job delivery');
        return;
    }
    if (!(invokeDoneAt >= rec.respondedAt)) {
        fail(
            checkNum,
            name,
            `the invoke response arrived ${rec.respondedAt - invokeDoneAt}ms BEFORE the job response was sent — ` +
                'the delivery was started but not awaited'
        );
        return;
    }

    // The activation window: onActivation() runs before any turn exists, so a
    // dispatch() from it has no turn budget and no turn collector unless the
    // host opened one for activation itself. Getting this wrong is not a
    // degraded case — the refusal escapes the bootstrap, php.run() unwinds, and
    // the residency is poisoned on every re-activation, forever. A fresh id
    // makes this a genuinely cold activation.
    const bootId = atomId('boot');
    listener.clear();
    const booted = await invoke('Boot', bootId, 'ping', []);
    const bootDoneAt = Date.now();
    if (booted.status !== 200 || booted.data?.error) {
        fail(
            checkNum,
            name,
            `invoking a fresh Boot atom failed: ${JSON.stringify(booted.data)} — dispatch() from onActivation() ` +
                'must not fail the activation'
        );
        return;
    }
    if (booted.data.result?.activations !== 1) {
        fail(checkNum, name, `Boot.ping().activations=${JSON.stringify(booted.data.result)} (expected 1)`);
        return;
    }
    const bootJobs = listener.records.filter((r) => r.kind === 'job');
    if (bootJobs.length !== 1) {
        fail(
            checkNum,
            name,
            `the listener saw ${bootJobs.length} job deliveries from onActivation() (expected 1): ` +
                JSON.stringify(listener.records.map((r) => r.kind))
        );
        return;
    }
    if (bootJobs[0].parsed?.args?.note !== 'boot:0' || bootJobs[0].parsed?.args?.atomId !== bootId) {
        fail(checkNum, name, `the activation job's args were ${JSON.stringify(bootJobs[0].parsed?.args)}`);
        return;
    }
    if (!bootJobs[0].signatureValid) {
        fail(checkNum, name, "the activation job's signature did not verify");
        return;
    }
    if (bootJobs[0].respondedAt === null || !(bootDoneAt >= bootJobs[0].respondedAt)) {
        fail(
            checkNum,
            name,
            'the invoke response arrived before the activation-time job response was sent — the activation window ' +
                'started a delivery it did not await'
        );
        return;
    }

    // The activation window's app() leg (park op app.call, X-Atoms-Kind=methods).
    // Boot.onActivation() calls $this->app()->echoBig(1) BEFORE it dispatches, so
    // the listener must have seen exactly one signed methods request from the
    // activation — which also proves the activation budget was re-stamped past
    // wasm boot + migrations (mvp-spec.md §The turn deadline): under this run's
    // small ATOMS_TURN_DEADLINE_MS a budget still charged for boot would have
    // thrown TurnDeadlineExceeded in onActivation and failed the invoke above.
    const bootMethods = listener.records.filter((r) => r.kind === 'methods');
    if (bootMethods.length !== 1) {
        fail(
            checkNum,
            name,
            `the listener saw ${bootMethods.length} app() (methods) requests from onActivation() (expected 1): ` +
                JSON.stringify(listener.records.map((r) => r.kind))
        );
        return;
    }
    if (!bootMethods[0].signatureValid) {
        fail(checkNum, name, "the activation app() call's signature did not verify");
        return;
    }
    if (bootMethods[0].parsed?.method !== 'echoBig' || bootMethods[0].parsed?.atom?.type !== 'Boot') {
        fail(
            checkNum,
            name,
            `the activation app() call was ${JSON.stringify({
                method: bootMethods[0].parsed?.method,
                atom: bootMethods[0].parsed?.atom,
            })} (expected echoBig on Boot — the app() budget was not fresh past boot+migrations)`
        );
        return;
    }

    pass(
        checkNum,
        name,
        'kind=job, signed, args keyed by promoted property name; the turn AND the activation both held their ' +
            'response until the delivery completed, and onActivation()’s app() reached the monolith on a fresh budget'
    );
});

// CHECK 17: dispatch() transaction semantics
checks.push(async () => {
    const checkNum = 17;
    const name = 'dispatch() transaction semantics (buffer/drop/deliver)';
    if (!listener) {
        skip(checkNum, name, 'no callback listener', REQUIRE_CALLBACK_CHECKS, 'ATOMS_CALLBACK_PORT');
        return;
    }

    const problems = [];

    // fail=true: the transaction rolls back, so the job must never be
    // delivered — as durable as the row it was dispatched next to.
    {
        const id = atomId('transfer-fail');
        listener.clear();
        const res = await invoke('Vault', id, 'transferAndNotify', [true]);
        if (res.status !== 500 || res.data?.error?.code !== 'atom_exception') {
            problems.push(`fail=true: status=${res.status}/${res.data?.error?.code} (expected 500/atom_exception)`);
        }
        if (listener.records.length !== 0) {
            problems.push(`fail=true: listener recorded ${listener.records.length} requests (expected 0 — rolled back)`);
        }
        const row = await invoke('Vault', id, 'getBig', ['transfer-and-notify']);
        if (parseInt64(row.data?.result) !== 0n) {
            problems.push(`fail=true: the rolled-back row survived: ${JSON.stringify(row.data)}`);
        }
    }

    // fail=false: the transaction commits, so exactly one delivery happens
    // and the row is present.
    {
        const id = atomId('transfer-ok');
        listener.clear();
        const res = await invoke('Vault', id, 'transferAndNotify', [false]);
        if (res.status !== 200 || res.data?.result !== true) {
            problems.push(`fail=false: status=${res.status} result=${JSON.stringify(res.data)}`);
        }
        if (listener.records.length !== 1) {
            problems.push(`fail=false: listener recorded ${listener.records.length} requests (expected 1)`);
        } else if (listener.records[0].kind !== 'job') {
            problems.push(`fail=false: X-Atoms-Kind=${listener.records[0].kind} (expected job)`);
        }
        const row = await invoke('Vault', id, 'getBig', ['transfer-and-notify']);
        if (parseInt64(row.data?.result) !== 1n) {
            problems.push(`fail=false: the committed row is missing: ${JSON.stringify(row.data)}`);
        }
    }

    // Dispatched outside any transaction, then an uncaught throw: the
    // documented asymmetry — as durable as a non-transactional write, so it
    // is delivered despite the turn failing.
    {
        const id = atomId('notify-then-throw');
        listener.clear();
        const res = await invoke('Vault', id, 'notifyThenThrow', []);
        const invokeDoneAt = Date.now();
        if (res.status !== 500 || res.data?.error?.code !== 'atom_exception') {
            problems.push(`notifyThenThrow: status=${res.status}/${res.data?.error?.code} (expected 500/atom_exception)`);
        }
        if (listener.records.length !== 1) {
            problems.push(
                `notifyThenThrow: listener recorded ${listener.records.length} requests (expected 1 — delivered despite the throw)`
            );
        } else {
            // A FAILING turn settles its deliveries too — `.finally`, not
            // `.then`. Same ordering assertion as check 16:
            // the 500 must not go out before the job response came back.
            const rec = listener.records[0];
            if (rec.respondedAt === null) {
                problems.push('notifyThenThrow: the listener never finished responding to the job delivery');
            } else if (!(invokeDoneAt >= rec.respondedAt)) {
                problems.push(
                    `notifyThenThrow: the 500 arrived ${rec.respondedAt - invokeDoneAt}ms before the job response ` +
                        'was sent — a failing turn started its delivery without awaiting it'
                );
            }
        }
    }

    if (problems.length === 0) {
        pass(checkNum, name, `buffered-on-commit/dropped-on-rollback inside tx; delivered-despite-throw outside tx`);
    } else {
        fail(checkNum, name, problems.join('; '));
    }
});

// CHECK 18: connect, onConnect observed, params delivered; bad upgrades
// refused before any DO work; the invocable_method() denylist for socket
// handlers.
checks.push(async () => {
    const checkNum = 18;
    const name = 'ws connect: onConnect observed, params delivered, refusals, invocable_method denylist';
    const problems = [];
    const id = atomId('room-connect');

    const sock = await openSocket(`/ws/Room/${id}?channels=lobby&hello=world`);
    try {
        const frame = await sock.next();
        let welcome = null;
        try {
            welcome = JSON.parse(/** @type {string} */ (frame));
        } catch {
            problems.push(`welcome frame was not JSON: ${frame}`);
        }
        if (welcome) {
            if (welcome.kind !== 'welcome') problems.push(`welcome.kind=${JSON.stringify(welcome.kind)} (expected "welcome")`);
            if (typeof welcome.conn !== 'string' || welcome.conn === '') {
                problems.push(`welcome.conn=${JSON.stringify(welcome.conn)} (expected a non-empty string)`);
            }
            if (JSON.stringify(welcome.params) !== JSON.stringify({ channels: 'lobby', hello: 'world' })) {
                problems.push(
                    `welcome.params=${JSON.stringify(welcome.params)} (expected {"channels":"lobby","hello":"world"})`
                );
            }
        }

        const stats = await invoke('Room', id, 'stats', []);
        if (stats.data?.result?.connects !== 1) {
            problems.push(`stats().connects=${stats.data?.result?.connects} (expected 1)`);
        }
    } finally {
        await sock.close();
    }

    // A bad upgrade must be refused before any DO is touched.
    const tooManyChannels = Array.from({ length: 40 }, (_, i) => `c${i}`).join(',');
    const badChannels = await wsHandshakeAttempt(`/ws/Room/${atomId('room-badchan')}?channels=${tooManyChannels}`);
    if (badChannels.status !== 400 || badChannels.data?.error?.code !== 'invalid_request') {
        problems.push(
            `40 channels gave ${badChannels.status}/${badChannels.data?.error?.code} (expected 400/invalid_request)`
        );
    }

    // Counter declares no WebSocket handlers ("websocket": false).
    const noHandlers = await wsHandshakeAttempt(`/ws/Counter/${atomId('room-nohandlers')}`);
    if (noHandlers.status !== 501 || noHandlers.data?.error?.code !== 'not_supported') {
        problems.push(`/ws/Counter gave ${noHandlers.status}/${noHandlers.data?.error?.code} (expected 501/not_supported)`);
    }

    // The invocable_method() denylist. Every handler the
    // RUNTIME dispatches must be unreachable through POST /invoke, whatever
    // its visibility and whatever case the client spells it in:
    //
    //   - onConnect/onMessage/onDisconnect are public on Atom and Room
    //     overrides all three;
    //   - onTimer/onActivation/onDeactivation are protected on Atom, so they
    //     must be refused on a type that overrides one (Scheduler::onTimer)
    //     and on one that does not (Room);
    //   - PHP method names are case-insensitive, so `ONMESSAGE` really does
    //     reach onMessage() unless the denylist compares canonical names. That
    //     spelling is the bypass this check exists to close.
    //
    // The refusals must also be indistinguishable: identical status, identical
    // code, identical message for a type that overrides a handler and one that
    // does not, or the response is an oracle for the Atom's private shape.
    /** @type {{label: string, res: any}[]} */
    const refusals = [];
    for (const [type, method] of /** @type {const} */ ([
        ['Room', 'onConnect'],
        ['Room', 'onMessage'],
        ['Room', 'onDisconnect'],
        ['Room', 'ONMESSAGE'],
        ['Room', 'onmessage'],
        ['Room', 'onTimer'],
        ['Room', 'onActivation'],
        ['Room', 'onDeactivation'],
        ['Scheduler', 'onTimer'],
        ['Scheduler', 'onMessage'],
    ])) {
        const res = await invoke(type, type === 'Room' ? id : atomId('sched-denylist'), method, []);
        refusals.push({ label: `${type}::${method}`, res });
        if (res.status !== 404 || res.data?.error?.code !== 'method_not_found') {
            problems.push(
                `POST /invoke .../${type}/${method} gave ${res.status}/${res.data?.error?.code} ` +
                    '(expected 404/method_not_found)'
            );
        }
    }

    // No oracle: Room OVERRIDES onMessage and Scheduler does not; Scheduler
    // OVERRIDES onTimer and Room does not. Each pair must read identically
    // apart from the class name the message names.
    const messageOf = (label) =>
        String(refusals.find((r) => r.label === label)?.res?.data?.error?.message ?? '').replace(
            /App\\Atoms\\\w+/g,
            '<Atom>'
        );
    for (const [a, b] of /** @type {const} */ ([
        ['Room::onMessage', 'Scheduler::onMessage'],
        ['Scheduler::onTimer', 'Room::onTimer'],
        ['Room::onMessage', 'Room::ONMESSAGE'],
    ])) {
        if (messageOf(a) !== messageOf(b)) {
            problems.push(
                `the refusal for ${a} (${JSON.stringify(messageOf(a))}) differs from ${b} ` +
                    `(${JSON.stringify(messageOf(b))}) — the response is an oracle for whether the Atom overrides it`
            );
        }
    }

    if (problems.length === 0) {
        pass(
            checkNum,
            name,
            'welcome frame observed with full params, bad upgrades refused pre-DO, all six runtime handlers ' +
                'refused on both an overriding and a non-overriding type (case variants included), with ' +
                'indistinguishable responses'
        );
    } else {
        fail(checkNum, name, problems.join('; '));
    }
});

// CHECK 19: echo round trip through onMessage + Connection::send(), text and binary
checks.push(async () => {
    const checkNum = 19;
    const name = 'ws echo round trip through onMessage + Connection::send() (text + binary)';
    const problems = [];
    const id = atomId('room-echo');

    const sock = await openSocket(`/ws/Room/${id}?channels=lobby`);
    try {
        await sock.next(); // welcome

        sock.send('echo:hi');
        const textFrame = await sock.next();
        if (textFrame !== 'echo:hi') problems.push(`text echo: got ${JSON.stringify(textFrame)} (expected "echo:hi")`);

        // Genuinely non-UTF-8 bytes: 0xFF/0xFE are invalid lead bytes and 0x80
        // is a lone continuation byte, so CfConnection::send()'s
        // content-based opcode rule (text iff valid UTF-8)
        // is guaranteed to answer with a binary frame. Low ASCII bytes like
        // [1,2,3,4,5] would come back as TEXT instead, by that same rule —
        // deliberately, not a bug — so they would not exercise this path.
        const bin = new Uint8Array([0xff, 0xfe, 0x80, 0x00, 0xfd]);
        sock.send(bin);
        const binFrame = await sock.next();
        if (!(binFrame instanceof ArrayBuffer)) {
            problems.push(`binary echo: got a ${typeof binFrame} frame, not an ArrayBuffer`);
        } else {
            const got = new Uint8Array(/** @type {ArrayBuffer} */ (binFrame));
            if (got.length !== bin.length || !bin.every((b, i) => got[i] === b)) {
                problems.push(`binary echo: got [${got}] (expected [${bin}])`);
            }
        }

        const stats = await invoke('Room', id, 'stats', []);
        if (stats.data?.result?.lastBinary !== true) {
            problems.push(`stats().lastBinary=${stats.data?.result?.lastBinary} (expected true)`);
        }
        if (stats.data?.result?.messages !== 2) {
            problems.push(`stats().messages=${stats.data?.result?.messages} (expected 2)`);
        }
    } finally {
        await sock.close();
    }

    if (problems.length === 0) {
        pass(checkNum, name, 'text frame and 5-byte binary frame both echoed exactly; lastBinary observed');
    } else {
        fail(checkNum, name, problems.join('; '));
    }
});

// CHECK 20: broadcast reaches the channel and only the channel
checks.push(async () => {
    const checkNum = 20;
    const name = 'broadcast() reaches the channel and only the channel';
    const problems = [];
    const id = atomId('room-bcast');

    const a = await openSocket(`/ws/Room/${id}?channels=lobby`);
    const b = await openSocket(`/ws/Room/${id}?channels=lobby`);
    const c = await openSocket(`/ws/Room/${id}?channels=other`);
    try {
        await a.next();
        await b.next();
        await c.next(); // welcomes

        a.send('bcast:lobby:hello');

        const want = { kind: 'broadcast', channel: 'lobby', payload: { text: 'hello' } };
        for (const [label, sock] of /** @type {const} */ ([
            ['A', a],
            ['B', b],
        ])) {
            const frame = await sock.next();
            let parsed = null;
            try {
                parsed = JSON.parse(/** @type {string} */ (frame));
            } catch {
                problems.push(`${label}: broadcast frame was not JSON: ${frame}`);
                continue;
            }
            if (JSON.stringify(parsed) !== JSON.stringify(want)) {
                problems.push(`${label}: broadcast frame ${JSON.stringify(parsed)} !== ${JSON.stringify(want)}`);
            }
        }

        // C, on a different channel, must receive nothing within a bounded wait.
        try {
            const spurious = await c.next(800);
            problems.push(`C (channel "other") received a frame it should not have: ${JSON.stringify(spurious)}`);
        } catch {
            /* expected: the wait times out */
        }

        // broadcast() to a channel with no members is not an error, and the
        // turn still succeeds — proved by the sender staying healthy.
        a.send('bcast:nobody:x');
        try {
            const spurious = await b.next(600);
            problems.push(`B received an unexpected frame from the empty-channel broadcast: ${JSON.stringify(spurious)}`);
        } catch {
            /* expected */
        }
        a.send('echo:still-alive');
        const echoed = await a.next();
        if (echoed !== 'echo:still-alive') {
            problems.push(`A unhealthy after the empty-channel broadcast: ${JSON.stringify(echoed)}`);
        }

        // broadcast() from INSIDE a committed db()->transaction() — the
        // documented transaction-send hazard: a ws op is legal at all while the
        // guest is parked inside ctx.storage.transactionSync()'s callback. It
        // was probed and found legal (mvp-spec.md §Appendix item 4); the
        // assertion here is what keeps it from silently regressing into the
        // pre-decided fallback (a tx_state refusal), which would be a
        // behaviour change no test would have caught.
        a.send('bcasttx:lobby:in-a-transaction');
        const wantTx = { kind: 'broadcast', channel: 'lobby', payload: { text: 'in-a-transaction' } };
        for (const [label, sock] of /** @type {const} */ ([
            ['A', a],
            ['B', b],
        ])) {
            const frame = await sock.next();
            let parsed = null;
            try {
                parsed = JSON.parse(/** @type {string} */ (frame));
            } catch {
                problems.push(`${label}: transactional broadcast frame was not JSON: ${frame}`);
                continue;
            }
            if (JSON.stringify(parsed) !== JSON.stringify(wantTx)) {
                problems.push(
                    `${label}: transactional broadcast frame ${JSON.stringify(parsed)} !== ${JSON.stringify(wantTx)}`
                );
            }
        }

        // ...and the transaction it ran inside really did commit.
        const txStats = await invoke('Room', id, 'stats', []);
        if (txStats.status !== 200 || txStats.data?.error) {
            problems.push(`stats() after the transactional broadcast failed: ${JSON.stringify(txStats.data)}`);
        }
    } finally {
        await a.close();
        await b.close();
        await c.close();
    }

    if (problems.length === 0) {
        pass(
            checkNum,
            name,
            'broadcast reached A and B on "lobby" with the exact pinned wire shape, not C on "other"; ' +
                'an empty-channel broadcast is a no-op, not an error; a broadcast from inside a committed ' +
                'transaction is delivered'
        );
    } else {
        fail(checkNum, name, problems.join('; '));
    }
});

// CHECK 21: THE BIG ONE — survival across a real hibernation
checks.push(async () => {
    const checkNum = 21;
    const name = 'ws survival across a real hibernation (THE BIG ONE)';
    const problems = [];
    const id = atomId('room-evict');

    const sock = await openSocket(`/ws/Room/${id}?channels=lobby`);
    try {
        const welcome = JSON.parse(/** @type {string} */ (await sock.next()));
        const connId = welcome.conn;

        sock.send('echo:before');
        const before = await sock.next();
        if (before !== 'echo:before') problems.push(`pre-eviction echo: ${JSON.stringify(before)} (expected "echo:before")`);

        const beforeInfo = await debugInfo('Room', id);

        console.log(`   (waiting ${EVICTION_WAIT_MS}ms for eviction...)`);
        await new Promise((r) => setTimeout(r, EVICTION_WAIT_MS));

        const afterInfo = await debugInfo('Room', id);

        // This is what makes the check honest. Without it the check passes on
        // a warm residency and asserts nothing — see CHECK 12's identical rule.
        if (!(afterInfo.constructions > beforeInfo.constructions)) {
            fail(
                checkNum,
                name,
                `constructions did not increase (${beforeInfo.constructions} -> ${afterInfo.constructions}): ` +
                    'the Durable Object was never evicted, so nothing was proved'
            );
            return;
        }

        sock.send('echo:after');
        const after = await sock.next();
        if (after !== 'echo:after') problems.push(`post-wake echo on the SAME socket: ${JSON.stringify(after)}`);

        sock.send('id?');
        const idFrame = await sock.next();
        if (idFrame !== `id:${connId}`) {
            problems.push(`post-wake id: ${JSON.stringify(idFrame)} (expected "id:${connId}") — the connection id changed`);
        }

        const stats = await invoke('Room', id, 'stats', []);
        if (stats.data?.result?.connects !== 1) {
            problems.push(`stats().connects=${stats.data?.result?.connects} (expected 1 — onConnect must not re-run)`);
        }
        if (stats.data?.result?.connectsThisResidency !== 0) {
            problems.push(
                `stats().connectsThisResidency=${stats.data?.result?.connectsThisResidency} (expected 0 — ` +
                    'this residency is new, cross-checking constructions)'
            );
        }

        if (!(afterInfo.ws?.sockets >= 1)) problems.push(`afterInfo.ws.sockets=${afterInfo.ws?.sockets} (expected >=1)`);
        const afterConn = (afterInfo.ws?.connections ?? []).find((/** @type {any} */ c) => c.id === connId);
        if (!afterConn || JSON.stringify(afterConn.channels) !== JSON.stringify(['lobby'])) {
            problems.push(`post-wake debug channels for ${connId}: ${JSON.stringify(afterConn?.channels)} (expected ["lobby"])`);
        }
        if (!afterConn || !(afterConn.tags ?? []).includes('ch:lobby')) {
            problems.push(`getTags() after the wake did not include "ch:lobby": ${JSON.stringify(afterConn?.tags)}`);
        }

        await sock.close(1000, 'done');
        let disconnects = 0;
        const deadline = Date.now() + 8000;
        while (Date.now() < deadline) {
            const s = await invoke('Room', id, 'stats', []);
            disconnects = s.data?.result?.disconnects;
            if (disconnects === 1) break;
            await new Promise((r) => setTimeout(r, 200));
        }
        if (disconnects !== 1) {
            problems.push(`stats().disconnects=${disconnects} (expected 1 — onDisconnect must fire after a wake)`);
        }
    } finally {
        try {
            await sock.close();
        } catch {
            /* already closed */
        }
    }

    if (problems.length === 0) {
        pass(
            checkNum,
            name,
            'constructions grew across the wait, same conn id and channels survived (tags intact), ' +
                'onConnect did not re-run, onDisconnect fired post-wake'
        );
    } else {
        fail(checkNum, name, problems.join('; '));
    }
});

// CHECK 22: send() to a dead connection
checks.push(async () => {
    const checkNum = 22;
    const name = "send() to a dead connection: ConnectionClosed, scoped to the call";
    const problems = [];
    const id = atomId('room-poke');

    const a = await openSocket(`/ws/Room/${id}?channels=lobby`);
    const b = await openSocket(`/ws/Room/${id}?channels=lobby`);
    try {
        await a.next();
        await b.next(); // welcomes

        a.send('id?');
        const aId = String(await a.next()).replace(/^id:/, '');
        b.send('id?');
        const bId = String(await b.next()).replace(/^id:/, '');

        await b.close(1000, 'bye');

        let disconnects = 0;
        let deadline = Date.now() + 8000;
        while (Date.now() < deadline) {
            const s = await invoke('Room', id, 'stats', []);
            disconnects = s.data?.result?.disconnects;
            if (disconnects === 1) break;
            await new Promise((r) => setTimeout(r, 200));
        }
        if (disconnects !== 1) problems.push(`stats().disconnects=${disconnects} (expected 1 before poking)`);

        a.send(`poke:${bId}`);
        let lastPoke = null;
        deadline = Date.now() + 8000;
        while (Date.now() < deadline) {
            const s = await invoke('Room', id, 'stats', []);
            lastPoke = s.data?.result?.lastPoke;
            if (lastPoke !== null && lastPoke !== undefined) break;
            await new Promise((r) => setTimeout(r, 150));
        }
        if (lastPoke !== 'ConnectionClosed') {
            problems.push(`stats().lastPoke=${JSON.stringify(lastPoke)} (expected "ConnectionClosed")`);
        }

        // The failure is scoped to the call, not the connection or the
        // residency: A's own socket must still be open and still echo.
        a.send('echo:still-here');
        const echoed = await a.next();
        if (echoed !== 'echo:still-here') {
            problems.push(`A's socket unhealthy after poking a dead connection: ${JSON.stringify(echoed)}`);
        }
        void aId; // captured for symmetry/debuggability; not itself asserted on
    } finally {
        await a.close();
        try {
            await b.close();
        } catch {
            /* already closed */
        }
    }

    if (problems.length === 0) {
        pass(
            checkNum,
            name,
            'poke to a closed connection produced ConnectionClosed (typed, catchable), scoped to the call — ' +
                "A's socket kept working"
        );
    } else {
        fail(checkNum, name, problems.join('; '));
    }
});

// CHECK 23: timers fire, are consumed, are transactional, cancel works,
// errors are contained (M2 wave 3 — Durable Object alarms behind the Timers
// ABI).
checks.push(async () => {
    const checkNum = 23;
    const name = 'timers: fire, consume, transactional, cancel, errors contained';
    const problems = [];
    const id = atomId('scheduler');

    async function timerLog() {
        const r = await invoke('Scheduler', id, 'timerLog', []);
        if (r.status !== 200 || r.data?.error) {
            throw new Error(`timerLog failed: ${JSON.stringify(r.data)}`);
        }
        return (r.data.result ?? []).map((e) => e.name);
    }

    async function scheduledMs(timerName) {
        const r = await invoke('Scheduler', id, 'scheduledMs', [timerName]);
        if (r.status !== 200 || r.data?.error) {
            throw new Error(`scheduledMs failed: ${JSON.stringify(r.data)}`);
        }
        return r.data.result;
    }

    async function waitUntilLogged(timerName, timeoutMs = 15000) {
        const deadline = Date.now() + timeoutMs;
        let log = await timerLog();
        while (!log.includes(timerName) && Date.now() < deadline) {
            await new Promise((res) => setTimeout(res, 300));
            log = await timerLog();
        }
        return log;
    }

    // t1 fires once, and is consumed (no longer scheduled) afterwards.
    const armT1 = await invoke('Scheduler', id, 'arm', ['t1', 1500]);
    if (armT1.status !== 200 || armT1.data?.error) {
        problems.push(`arm(t1) failed: ${JSON.stringify(armT1.data)}`);
    }
    const afterT1 = await waitUntilLogged('t1');
    if (!afterT1.includes('t1')) {
        problems.push(`t1 did not fire within the poll window: log=${JSON.stringify(afterT1)}`);
    }
    if ((await scheduledMs('t1')) !== null) {
        problems.push('t1 is still scheduled after firing (expected consumed/null)');
    }

    // A timer scheduled from inside onTimer() fires too (chain-1 -> chain-2).
    const armChain = await invoke('Scheduler', id, 'arm', ['chain-1', 500]);
    if (armChain.status !== 200 || armChain.data?.error) {
        problems.push(`arm(chain-1) failed: ${JSON.stringify(armChain.data)}`);
    }
    const afterChain = await waitUntilLogged('chain-2');
    if (!afterChain.includes('chain-1') || !afterChain.includes('chain-2')) {
        problems.push(`chain did not complete: log=${JSON.stringify(afterChain)}`);
    }

    // A schedule() made inside a transaction that rolls back never fires.
    const rolled = await invoke('Scheduler', id, 'armInsideRollback', ['never', 500]);
    if (rolled.status !== 500 || rolled.data?.error?.code !== 'atom_exception') {
        problems.push(
            `armInsideRollback gave ${rolled.status}/${rolled.data?.error?.code} (expected 500/atom_exception)`
        );
    }
    await new Promise((r) => setTimeout(r, 3000));
    const afterRollback = await timerLog();
    if (afterRollback.includes('never')) {
        problems.push("'never' fired despite its schedule() being inside a rolled-back transaction");
    }
    if ((await scheduledMs('never')) !== null) {
        problems.push("'never' is still scheduled after its transaction rolled back");
    }

    // cancel() actually prevents the fire.
    await invoke('Scheduler', id, 'arm', ['t-cancel', 4000]);
    await invoke('Scheduler', id, 'cancelTimer', ['t-cancel']);
    await new Promise((r) => setTimeout(r, 4500));
    const afterCancel = await timerLog();
    if (afterCancel.includes('t-cancel')) {
        problems.push('t-cancel fired despite being cancelled');
    }

    // A throwing onTimer is still consumed at-most-once, and the residency
    // stays healthy for the atom's other timers/turns.
    await invoke('Scheduler', id, 'arm', ['boom-1', 500]);
    {
        const deadline = Date.now() + 8000;
        let ms = await scheduledMs('boom-1');
        while (ms !== null && Date.now() < deadline) {
            await new Promise((r) => setTimeout(r, 300));
            ms = await scheduledMs('boom-1');
        }
        if (ms !== null) {
            problems.push(`boom-1 was never consumed (still scheduled): ${ms}`);
        }
    }
    const afterBoom = await timerLog();
    if (afterBoom.includes('boom-1')) {
        problems.push('boom-1 wrote a log row despite throwing');
    }
    const healthy = await invoke('Scheduler', id, 'scheduledMs', ['nothing-scheduled-by-this-name']);
    if (healthy.status !== 200 || healthy.data?.error || healthy.data.result !== null) {
        problems.push(`residency unhealthy after a throwing onTimer: ${JSON.stringify(healthy.data)}`);
    }

    // __atoms_timers is reserved, exactly like __atoms_meta (check 10).
    const reserved = await invoke('Scheduler', id, 'readReservedTimers', []);
    if (reserved.status !== 200 || reserved.data?.error) {
        problems.push(`readReservedTimers failed: ${JSON.stringify(reserved.data)}`);
    } else if (
        reserved.data.result?.rejected !== true ||
        !String(reserved.data.result.message).includes('reserved_table')
    ) {
        problems.push(`__atoms_timers was not rejected: ${JSON.stringify(reserved.data.result)}`);
    }

    // Name validation: empty and over-long names are ATOMS-E085, not silently
    // accepted.
    const emptyName = await invoke('Scheduler', id, 'arm', ['', 100]);
    if (
        emptyName.status !== 500 ||
        emptyName.data?.error?.code !== 'atom_exception' ||
        !String(emptyName.data.error.message).includes('ATOMS-E085')
    ) {
        problems.push(
            `empty timer name gave ${emptyName.status}/${emptyName.data?.error?.code}: ` +
                `${JSON.stringify(emptyName.data?.error)} (expected 500/atom_exception carrying ATOMS-E085)`
        );
    }
    const longName = await invoke('Scheduler', id, 'arm', ['x'.repeat(300), 100]);
    if (
        longName.status !== 500 ||
        longName.data?.error?.code !== 'atom_exception' ||
        !String(longName.data.error.message).includes('ATOMS-E085')
    ) {
        problems.push(
            `over-long timer name gave ${longName.status}/${longName.data?.error?.code}: ` +
                `${JSON.stringify(longName.data?.error)} (expected 500/atom_exception carrying ATOMS-E085)`
        );
    }

    if (problems.length === 0) {
        pass(
            checkNum,
            name,
            't1/chain fired and consumed, rollback+cancel honored, throwing onTimer contained, ' +
                '__atoms_timers reserved, ATOMS-E085 validated'
        );
    } else {
        fail(checkNum, name, problems.join('; '));
    }
});

// CHECK 24: THE HONEST ONE — a Durable Object alarm wakes an evicted atom.
checks.push(async () => {
    const checkNum = 24;
    const name = 'alarm wakes an evicted atom (THE HONEST ONE)';
    const problems = [];
    const id = atomId('scheduler-wake');

    const beforeInfo = await debugInfo('Scheduler', id);

    const armed = await invoke('Scheduler', id, 'arm', ['wake-1', EVICTION_WAIT_MS + 4000]);
    if (armed.status !== 200 || armed.data?.error) {
        fail(checkNum, name, `arm(wake-1) failed: ${JSON.stringify(armed.data)}`);
        return;
    }

    // Idle the FULL eviction wait, exactly like checks 12/21 — never
    // shortened — so the residency genuinely gets evicted rather than merely
    // asserting on a warm one.
    console.log(`   (idling ${EVICTION_WAIT_MS}ms for eviction...)`);
    await new Promise((r) => setTimeout(r, EVICTION_WAIT_MS));

    // wake-1 is not due for another ~4s past the idle wait. Give the alarm a
    // window to fire entirely on its own — nothing from this suite has
    // touched the atom yet — before any poll request below could reactivate
    // it first via the ordinary invoke path and blur what actually woke it.
    await new Promise((r) => setTimeout(r, 4500));

    // Poll (bounded): confirms the alarm actually delivered wake-1, whether
    // it had already fired by the line above or fires during this window.
    const deadline = Date.now() + 15000;
    let log = [];
    for (;;) {
        const r = await invoke('Scheduler', id, 'timerLog', []);
        if (r.status !== 200 || r.data?.error) {
            fail(checkNum, name, `timerLog failed: ${JSON.stringify(r.data)}`);
            return;
        }
        log = (r.data.result ?? []).map((e) => e.name);
        if (log.includes('wake-1') || Date.now() >= deadline) break;
        await new Promise((res) => setTimeout(res, 300));
    }
    if (!log.includes('wake-1')) {
        fail(checkNum, name, 'wake-1 did not fire within the poll window');
        return;
    }

    const afterInfo = await debugInfo('Scheduler', id);

    // This is what makes the check honest. Without it the check passes on a
    // warm residency and asserts nothing — see CHECK 12/21's identical rule.
    if (!(afterInfo.constructions > beforeInfo.constructions)) {
        fail(
            checkNum,
            name,
            `constructions did not increase (${beforeInfo.constructions} -> ${afterInfo.constructions}): ` +
                'the Durable Object was never evicted, so nothing was proved'
        );
        return;
    }

    const fired = log.filter((n) => n === 'wake-1').length;
    if (fired !== 1) {
        problems.push(`wake-1 appears ${fired} times in the log (expected exactly 1)`);
    }
    // `fired_this_residency` is the whole point of this check, and it is
    // deliberately exact rather than `>= 1`. It counts timer dispatches in the
    // residency the debug read lands in — which is the residency the ALARM
    // created, because nothing else touched this Atom across the idle wait.
    // If that residency were evicted again between the poll above and this
    // read, the counter would come back 0 and the check would fail. That is
    // the correct direction to fail in: a 0 means the alarm's own residency
    // did not survive to be observed, so this run proved less than it claims,
    // and the honest response is to investigate the timing rather than to
    // relax the assertion to `>= 0` — which would make it vacuous, exactly
    // like a shortened ATOMS_EVICTION_WAIT_MS makes check 12 vacuous. Do not
    // weaken it.
    if (afterInfo.timers?.fired_this_residency !== 1) {
        problems.push(`timers.fired_this_residency=${afterInfo.timers?.fired_this_residency} (expected 1)`);
    }

    if (problems.length === 0) {
        pass(
            checkNum,
            name,
            `constructions ${beforeInfo.constructions} -> ${afterInfo.constructions} across the idle wait, ` +
                'wake-1 fired exactly once via the alarm'
        );
    } else {
        fail(checkNum, name, problems.join('; '));
    }
});

// CHECK 25: a CLOSE has to wake a hibernated Durable Object.
//
// Check 21 proves a socket survives eviction and that onDisconnect fires after
// a wake — but it sends a frame across the eviction first, which re-activates
// the residency, so by the time it closes, the DO is warm again. This is the
// case check 21 doesn't cover: the close itself is the
// FIRST event after the hibernation, with no traffic in between. That is the
// path a real client takes when it goes away quietly (a laptop lid, a mobile
// network drop), and it is the one that would leave connections leaked forever
// if the platform did not deliver it.
checks.push(async () => {
    const checkNum = 25;
    const name = 'close wakes a hibernated Durable Object';
    const problems = [];
    const id = atomId('room-closewake');

    const sock = await openSocket(`/ws/Room/${id}?channels=lobby`);
    let closed = false;
    try {
        await sock.next(); // welcome

        sock.send('echo:before-idle');
        const before = await sock.next();
        if (before !== 'echo:before-idle') {
            problems.push(`pre-idle echo: ${JSON.stringify(before)} (expected "echo:before-idle")`);
        }

        const beforeInfo = await debugInfo('Room', id);

        // The full wait, never shortened — same rule as checks 12/21/24. And
        // NOTHING may touch this Atom during it, or afterwards until the
        // observation below: a debug read or a stats() invoke would re-activate
        // the residency and turn the close into an ordinary warm-path event,
        // which is precisely what this check exists NOT to assert on.
        console.log(`   (idling ${EVICTION_WAIT_MS}ms with no traffic at all, then closing...)`);
        await new Promise((r) => setTimeout(r, EVICTION_WAIT_MS));

        await sock.close(1000, 'quiet-exit');
        closed = true;

        // The close, and nothing else, now has to wake the Durable Object and
        // run a ws.close turn on it.
        //
        // The observation window is bounded on BOTH sides, which is why it is
        // polled rather than slept: a wake costs a full activation, so reading
        // too early sees nothing — but the woken residency starts its own idle
        // clock the moment its close turn ends, so a long silent sleep can
        // watch it get evicted AGAIN and land on a third, empty residency.
        // Measured 2026-08-12: the close activated the object ~170ms after it
        // was issued (host log `atoms.do.activated`, constructions 2, ordered
        // BEFORE the first debug request), and a fully silent 8s window was
        // long enough for that residency to be evicted before it was read.
        //
        // `GET /debug` never activates PHP and never dispatches a WebSocket
        // turn, so polling it cannot manufacture the observation being made.
        const WAKE_WINDOW_MS = 6000;
        console.log(`   (watching ${WAKE_WINDOW_MS}ms for the close alone to wake it...)`);
        let afterInfo = null;
        // Wall-clock just before our first debug read after the close. GET /debug
        // constructs the DO (constructions bumps in the constructor) even though
        // it never activates PHP, so the residency we later observe must be shown
        // to have existed BEFORE this instant — otherwise our own first poll,
        // not the close, could be what created it (F2 attribution assertion).
        let firstPollAt = null;
        const wakeDeadline = Date.now() + WAKE_WINDOW_MS;
        for (;;) {
            await new Promise((r) => setTimeout(r, 250));
            if (firstPollAt === null) firstPollAt = Date.now();
            afterInfo = await debugInfo('Room', id);
            const woke = afterInfo.constructions > beforeInfo.constructions && afterInfo.ws?.turns_this_residency >= 1;
            if (woke || Date.now() >= wakeDeadline) break;
        }

        // The honesty assertion, the same one checks 12/21/24 carry: without
        // it this passes on a residency that was never evicted and proves
        // nothing about waking at all.
        if (!(afterInfo.constructions > beforeInfo.constructions)) {
            fail(
                checkNum,
                name,
                `constructions did not increase (${beforeInfo.constructions} -> ${afterInfo.constructions}): ` +
                    'the Durable Object was never evicted, so nothing was proved'
            );
            return;
        }
        // ...and the assertion that makes it about the CLOSE. This residency
        // has served a WebSocket turn, and no request from this suite caused
        // one: the close did.
        if (!(afterInfo.ws?.turns_this_residency >= 1)) {
            problems.push(
                `ws.turns_this_residency=${afterInfo.ws?.turns_this_residency} (expected >=1 — the close did not ` +
                    'wake the Durable Object on its own)'
            );
        }
        // A wake is not a new connection: nothing accepted a socket here.
        if (afterInfo.ws?.connects_this_residency !== 0) {
            problems.push(
                `ws.connects_this_residency=${afterInfo.ws?.connects_this_residency} (expected 0 — the residency ` +
                    'that ran onDisconnect was created by the close, not by an accept)'
            );
        }
        // The attribution assertion: the wake we observed must NOT be creditable
        // to our own first debug poll. `resident_ms` is the residency's own age
        // (`now() - bornAt`); if it is already GREATER than the time elapsed
        // since our first poll, the residency necessarily existed before that
        // poll, so the poll did not construct it — the close did. If instead the
        // first `GET /debug` were what constructed the DO (a slow close-wake),
        // `resident_ms` would be at most the time since that poll, and this
        // fails. This is the same shape of honesty guard as the constructions
        // check above, aimed at the one residency the suite itself could have
        // manufactured.
        const sincePoll = Date.now() - firstPollAt;
        if (!(afterInfo.resident_ms > sincePoll)) {
            problems.push(
                `resident_ms=${afterInfo.resident_ms} is not greater than the ${sincePoll}ms since the first debug ` +
                    'poll — the observed residency was born no earlier than that poll, so this run cannot attribute ' +
                    'the wake to the close rather than to the poll'
            );
        }

        // The durable cross-check: onDisconnect actually ran, exactly once, and
        // onConnect did not re-run.
        const stats = await invoke('Room', id, 'stats', []);
        if (stats.data?.result?.disconnects !== 1) {
            problems.push(
                `stats().disconnects=${stats.data?.result?.disconnects} (expected 1 — a close on a hibernated DO ` +
                    'must still deliver onDisconnect, exactly once)'
            );
        }
        if (stats.data?.result?.connects !== 1) {
            problems.push(`stats().connects=${stats.data?.result?.connects} (expected 1 — onConnect must not re-run)`);
        }
    } finally {
        if (!closed) {
            try {
                await sock.close();
            } catch {
                /* already closed */
            }
        }
    }

    if (problems.length === 0) {
        pass(
            checkNum,
            name,
            'constructions grew across a fully idle wait, the close alone woke the DO, onDisconnect fired exactly ' +
                'once and onConnect did not re-run'
        );
    } else {
        fail(checkNum, name, problems.join('; '));
    }
});

// CHECK 26: pdo surface tripwire (M1 design §1.7).
//
// Probe::surfaceAudit() reflects the RUNTIME \PDO / \PDOStatement and
// asserts every public member is genuinely declared on Atoms\Cf\AtomsPDO /
// Atoms\Cf\AtomsStatement — never left to fall through to the throwaway
// sqlite::memory: carrier connection. This check does not merely assert
// `violations` is empty: it also asserts the audit actually DID something
// (R7's floors), so an audit that silently enumerated nothing cannot pass
// vacuously — the same discipline check 24 applies to `fired_this_residency`.
checks.push(async () => {
    const checkNum = 26;
    const name = 'pdo surface tripwire';
    const problems = [];
    const id = atomId('probe-surface');

    const res = await invoke('Probe', id, 'surfaceAudit', []);
    if (res.status !== 200) {
        fail(checkNum, name, `HTTP ${res.status}: ${JSON.stringify(res.data)}`);
        return;
    }

    const result = res.data?.result;
    if (!result || typeof result !== 'object') {
        fail(checkNum, name, `no result object in response: ${JSON.stringify(res.data)}`);
        return;
    }

    if (result.ok !== true) {
        problems.push(`ok=${JSON.stringify(result.ok)} (expected true)`);
    }

    const violations = Array.isArray(result.violations) ? result.violations : null;
    if (violations === null) {
        problems.push(`violations is not an array: ${JSON.stringify(result.violations)}`);
    } else if (violations.length !== 0) {
        problems.push(`${violations.length} violation(s): ${JSON.stringify(violations, null, 2)}`);
    }

    const counts = result.counts || {};
    if (!(counts.pdo_methods >= 15)) {
        problems.push(`counts.pdo_methods=${counts.pdo_methods} (expected >= 15)`);
    }
    if (!(counts.stmt_methods >= 19)) {
        problems.push(`counts.stmt_methods=${counts.stmt_methods} (expected >= 19)`);
    }
    if (!(counts.pinned_fetch >= 24)) {
        problems.push(`counts.pinned_fetch=${counts.pinned_fetch} (expected >= 24)`);
    }
    // M1 review F-18 (MINOR): R7's original floors covered methods and
    // pinned fetch modes but not properties, interfaces, or statics — an
    // audit that silently enumerated zero of any of those would still have
    // passed. Additive floors, strengthening check 26 without touching an
    // existing assertion.
    if (!(counts.properties >= 1)) {
        problems.push(`counts.properties=${counts.properties} (expected >= 1)`);
    }
    if (!(counts.interfaces >= 2)) {
        problems.push(`counts.interfaces=${counts.interfaces} (expected >= 2)`);
    }
    if (!(counts.pdo_statics >= 1)) {
        problems.push(`counts.pdo_statics=${counts.pdo_statics} (expected >= 1)`);
    }

    const membersChecked = Array.isArray(result.members_checked) ? result.members_checked : [];
    if (membersChecked.length === 0) {
        problems.push('members_checked is empty (the audit enumerated nothing)');
    }

    if (typeof result.php !== 'string' || !result.php.startsWith('8.3.')) {
        problems.push(`php=${JSON.stringify(result.php)} (expected to start with "8.3.")`);
    }

    // Every allowlist entry must have run its assertion and passed.
    const allowlist = Array.isArray(result.allowlist) ? result.allowlist : [];
    for (const entry of allowlist) {
        if (entry?.asserted !== true) {
            problems.push(`allowlist entry ${JSON.stringify(entry?.id)} has asserted=${JSON.stringify(entry?.asserted)}`);
        }
    }

    // Orchestrator override on design §1.7: exact SET equality on allowlist
    // ids, not merely a length cap — a renamed or silently added entry must
    // fail this check by name, not just by count.
    const allowlistIds = allowlist.map((e) => e?.id).sort();
    const expectedIds = ['PDOStatement::$queryString'];
    if (JSON.stringify(allowlistIds) !== JSON.stringify(expectedIds)) {
        problems.push(`allowlist ids = ${JSON.stringify(allowlistIds)} (expected exactly ${JSON.stringify(expectedIds)})`);
    }

    if (problems.length === 0) {
        pass(
            checkNum,
            name,
            `${membersChecked.length} members checked, 0 violations, php=${result.php}, ` +
                `pdo_methods=${counts.pdo_methods} stmt_methods=${counts.stmt_methods} pinned_fetch=${counts.pinned_fetch}`
        );
    } else {
        fail(checkNum, name, problems.join('; '));
    }
});

// CHECK 27: pdo comparator integrity (M1 design §2.11).
//
// Probe::comparatorSanity() builds a fresh native in-guest
// `new \PDO('sqlite::memory:')` and runs its five structural gates (S1-S5).
// This is the answer to "the comparator could be your own shim" — three of
// the five gates (FETCH_NAMED grouping, getColumnMeta, PDORow) are things
// Atoms\Cf\AtomsPDO cannot produce even in principle. NEVER skips: "we could
// not verify our own compatibility claims" is not a neutral outcome.
checks.push(async () => {
    const checkNum = 27;
    const name = 'pdo comparator integrity';
    const problems = [];
    const id = atomId('probe-sanity');

    const res = await invoke('Probe', id, 'comparatorSanity', []);
    if (res.status !== 200) {
        fail(checkNum, name, `HTTP ${res.status}: ${JSON.stringify(res.data)}`);
        return;
    }

    const result = res.data?.result;
    if (!result || typeof result !== 'object') {
        fail(checkNum, name, `no result object in response: ${JSON.stringify(res.data)}`);
        return;
    }

    if (result.ok !== true) {
        problems.push(`ok=${JSON.stringify(result.ok)}${result.detail ? ` — ${result.detail}` : ''}`);
    }

    const gates = result.gates || {};
    for (const gate of ['S1', 'S2', 'S3', 'S4', 'S5']) {
        if (gates[gate] !== true) {
            problems.push(`gate ${gate}=${JSON.stringify(gates[gate])} (expected true)`);
        }
    }

    if (problems.length === 0) {
        pass(checkNum, name, 'comparator constructed and all five gates (S1-S5) passed');
    } else {
        fail(checkNum, name, problems.join('; '));
    }
});

// CHECK 28: pdo differential matrix (M1 design §2.11).
//
// Orchestrator override on the design's report flow: Probe::differential()
// runs ONE group per invoke (a single 160-case turn is too close to
// ATOMS_TURN_DEADLINE_MS), so this check iterates
// Probe::differentialGroups() and merges each group's report runner-side
// before asserting. The pin file (test/pdo-expected.json) is the committed
// answer key: every observed non-match/non-informational case must be
// pinned with EXACTLY that class (rule 1), every pin entry must be observed
// with EXACTLY that class (rule 2 — catches a misconfigured/impostor
// comparator, a fill that landed without deleting its pin, or a renamed
// case), every pin's `why` must be >= 40 characters (rule 3), and every
// pin's class must be one of the four pinnable ones (rule 4).
checks.push(async () => {
    const checkNum = 28;
    const name = 'pdo differential matrix';
    const problems = [];

    const pinPath = join(__dirname, 'pdo-expected.json');
    let pinFile;
    try {
        pinFile = JSON.parse(readFileSync(pinPath, 'utf8'));
    } catch (err) {
        fail(checkNum, name, `could not read/parse ${pinPath}: ${err.message}`);
        return;
    }
    const pins = pinFile.cases || {};

    let groupsRes;
    try {
        groupsRes = await invoke('Probe', atomId('probe-diff-groups'), 'differentialGroups', []);
    } catch (err) {
        fail(checkNum, name, `differentialGroups() request failed: ${err.message}`);
        return;
    }
    if (groupsRes.status !== 200 || !Array.isArray(groupsRes.data?.result)) {
        fail(checkNum, name, `differentialGroups(): HTTP ${groupsRes.status}: ${JSON.stringify(groupsRes.data)}`);
        return;
    }
    const groups = groupsRes.data.result;
    if (groups.length === 0) {
        fail(checkNum, name, 'differentialGroups() returned an empty list — the matrix enumerated nothing');
        return;
    }

    // One atom id for the whole run: Probe::differential() resets and
    // reseeds the DO-side tables itself at the start of every group call, so
    // sharing one residency across groups is both faster (one activation)
    // and exercises the reset path for real.
    const id = atomId('probe-diff');

    const summary = {
        total: 0,
        match: 0,
        refused_by_us: 0,
        refused_by_both: 0,
        refused_by_comparator: 0,
        deviation: 0,
        informational: 0,
        error: 0,
    };
    const allCases = [];
    let comparatorSane = true;
    let php = null;

    for (const group of groups) {
        const res = await invoke('Probe', id, 'differential', [group]);
        if (res.status !== 200) {
            fail(checkNum, name, `differential(${JSON.stringify(group)}): HTTP ${res.status}: ${JSON.stringify(res.data)}`);
            return;
        }

        const result = res.data?.result;
        if (!result || typeof result !== 'object' || !Array.isArray(result.cases)) {
            fail(checkNum, name, `differential(${JSON.stringify(group)}): malformed result: ${JSON.stringify(res.data)}`);
            return;
        }

        if (typeof result.php === 'string') {
            php = result.php;
        }

        if (result.comparator?.ok !== true) {
            comparatorSane = false;
        }

        for (const key of Object.keys(summary)) {
            summary[key] += result.summary?.[key] || 0;
        }
        allCases.push(...result.cases);
    }

    // Assertion order matters: the FIRST failure is meant to be the most
    // informative one (design §2.11).
    if (!comparatorSane) {
        problems.push('comparator.sane !== true for at least one group — the differential run cannot be trusted');
    }

    if (problems.length === 0 && summary.error !== 0) {
        const broken = allCases.filter((c) => c.class === 'error').map((c) => `${c.id} (${c.detail})`);
        problems.push(`summary.error=${summary.error} (must be 0 — harness breakage, never pinnable): ${broken.join('; ')}`);
    }

    if (problems.length === 0 && !(summary.total >= 90)) {
        problems.push(`summary.total=${summary.total} (expected >= 90 — an anti-vacuous floor)`);
    }

    // M1 review F-2 (BLOCKER): 'informational' bypasses the pin rules
    // entirely (pin rule 1 skips it outright), so unlike every other class it
    // is not bounded by anything unless this check bounds it. It must be a
    // closed set of exactly one case id — the one case whose non-comparison
    // is a deliberate, documented, published exception (design §2.5's
    // rowCount()-after-SELECT note) — never a blanket escape a new case could
    // walk through by setting 'informational' => true.
    const INFORMATIONAL_IDS = new Set(['count.rowcount.select']);
    if (problems.length === 0) {
        const observedInformational = new Set(allCases.filter((c) => c.class === 'informational').map((c) => c.id));
        const unexpected = [...observedInformational].filter((id) => !INFORMATIONAL_IDS.has(id));
        const missing = [...INFORMATIONAL_IDS].filter((id) => !observedInformational.has(id));
        if (unexpected.length > 0 || missing.length > 0) {
            problems.push(
                `informational case-id set must be EXACTLY ${JSON.stringify([...INFORMATIONAL_IDS])}: ` +
                    `unexpected=${JSON.stringify(unexpected)} missing=${JSON.stringify(missing)}`
            );
        }
    }

    // M1 review round 2, R8: case ids must be globally unique BEFORE any
    // pin rule runs. A duplicate id would let two DIFFERENT cases share one
    // pin-file entry — pin rule 1 could pass with one of the pair silently
    // unpinned, and pin rule 2 could pass while masking a stale pin, both
    // because `pins[c.id]` and `byId.get(pinId)` (below) can only ever see
    // ONE of the colliding cases. Checked here, before pin rule 1, so a
    // collision fails with its OWN clear message rather than surfacing (or
    // not) as a confusing pin-rule mismatch.
    if (problems.length === 0) {
        const ids = allCases.map((c) => c.id);
        if (new Set(ids).size !== ids.length) {
            const seen = new Set();
            const dupes = new Set();
            for (const id of ids) {
                if (seen.has(id)) dupes.add(id);
                seen.add(id);
            }
            problems.push(`duplicate case id(s), must be globally unique: ${[...dupes].join(', ')}`);
        }
    }

    const PINNABLE = new Set(['refused_by_us', 'refused_by_both', 'refused_by_comparator', 'deviation']);

    if (problems.length === 0) {
        // Pin rule 1: every non-match/non-informational observed case must be
        // pinned with EXACTLY that class.
        const unpinned = [];
        for (const c of allCases) {
            if (c.class === 'match' || c.class === 'informational') continue;
            const pin = pins[c.id];
            if (!pin) {
                unpinned.push(`${c.id}: observed ${c.class}, no pin entry`);
            } else if (pin.class !== c.class) {
                unpinned.push(`${c.id}: observed ${c.class}, pinned as ${pin.class}`);
            }
        }
        if (unpinned.length > 0) {
            problems.push(`unpinned difference(s): ${unpinned.join(' | ')}`);
        }
    }

    if (problems.length === 0) {
        // Pin rule 2: every pin entry must be observed with EXACTLY that
        // class — a stale pin (renamed case, deleted case, or a fill that
        // landed without deleting the pin) fails here.
        const byId = new Map(allCases.map((c) => [c.id, c]));
        const stale = [];
        for (const [pinId, pin] of Object.entries(pins)) {
            const observed = byId.get(pinId);
            if (!observed) {
                stale.push(`${pinId}: pinned but no such case was observed this run (renamed or deleted?)`);
            } else if (observed.class !== pin.class) {
                stale.push(`${pinId}: pinned as ${pin.class}, observed ${observed.class} (stale pin)`);
            }
        }
        if (stale.length > 0) {
            problems.push(`stale pin(s): ${stale.join(' | ')}`);
        }
    }

    if (problems.length === 0) {
        // Pin rule 3: every `why` must be a real, non-trivial justification.
        const short = Object.entries(pins)
            .filter(([, pin]) => typeof pin.why !== 'string' || pin.why.length < 40)
            .map(([pinId, pin]) => `${pinId}: why is ${typeof pin.why === 'string' ? pin.why.length : 0} chars`);
        if (short.length > 0) {
            problems.push(`pin why too short (< 40 chars): ${short.join(' | ')}`);
        }
    }

    if (problems.length === 0) {
        // Pin rule 4: only these four classes may ever be pinned.
        const badClass = Object.entries(pins)
            .filter(([, pin]) => !PINNABLE.has(pin.class))
            .map(([pinId, pin]) => `${pinId}: class=${JSON.stringify(pin.class)}`);
        if (badClass.length > 0) {
            problems.push(`pin has a non-pinnable class: ${badClass.join(' | ')}`);
        }
    }

    if (problems.length === 0 && !(summary.match >= 55)) {
        problems.push(`summary.match=${summary.match} (expected >= 55 — a floor so "pin everything" cannot pass)`);
    }

    // Kept for CHECK 30, which re-uses this instead of re-running the
    // differential matrix a second time (design §5.4). Stored regardless of
    // whether this check passed or failed, so a run with an unpinned
    // difference still leaves evidence on disk.
    pdoMatrixReport = { php: php || 'unknown', cases: allCases };

    // Evidence for a human reading a failure, never a contract — gitignored
    // (cloudflare/worker/.gitignore). A read-only checkout makes this write a
    // no-op, never a failure (design §5.4).
    try {
        const resultsDir = join(__dirname, 'results');
        mkdirSync(resultsDir, { recursive: true });
        writeFileSync(join(resultsDir, 'pdo-matrix.json'), JSON.stringify(pdoMatrixReport, null, 2) + '\n');
    } catch {
        /* read-only checkout or similar — not a failure */
    }

    if (problems.length === 0) {
        pass(
            checkNum,
            name,
            `${groups.length} groups, total=${summary.total} match=${summary.match} refused_by_us=${summary.refused_by_us} ` +
                `refused_by_both=${summary.refused_by_both} refused_by_comparator=${summary.refused_by_comparator} ` +
                `deviation=${summary.deviation} informational=${summary.informational} error=${summary.error}, ` +
                `${Object.keys(pins).length} pinned`
        );
    } else {
        fail(checkNum, name, problems.join(' || '));
    }
});

// CHECK 29: sql result caps (M1 design §4.4).
//
// Same pattern as check 15: the Worker is started with small
// ATOMS_SQL_MAX_ROWS/ATOMS_SQL_MAX_RESULT_BYTES values, and the runner is
// told the same values via its OWN environment — never defaulted here.
// Probe::capProbe() builds result sets through a recursive CTE (CPU cost
// only, no writes), so the four legs below prove: the row cap does not fire
// below itself (29a), the row cap fires with the right code/detail (29b),
// the byte cap fires independently of the row cap (29c, sized to stay well
// under it), and the residency survives both failures (29d).
checks.push(async () => {
    const checkNum = 29;
    const name = 'sql result caps';
    if (SQL_MAX_ROWS === null || SQL_MAX_RESULT_BYTES === null) {
        skip(
            checkNum,
            name,
            'ATOMS_SQL_MAX_ROWS/ATOMS_SQL_MAX_RESULT_BYTES not set in the runner env — must match the values the Worker was started with',
            REQUIRE_SQL_CAP_CHECKS
        );
        return;
    }

    const problems = [];
    const id = atomId('probe-caps');

    // 29a — one row under the cap succeeds with EXACTLY that many rows: the
    // cap does not fire below itself.
    const under = await invoke('Probe', id, 'capProbe', ['rows', SQL_MAX_ROWS - 1, 0]);
    if (under.status !== 200 || under.data?.error) {
        problems.push(`29a: HTTP ${under.status}: ${JSON.stringify(under.data)} (expected 200, ok:true)`);
    } else if (under.data?.result?.ok !== true || under.data.result.rowCount !== SQL_MAX_ROWS - 1) {
        problems.push(`29a: result=${JSON.stringify(under.data?.result)} (expected ok:true, rowCount=${SQL_MAX_ROWS - 1})`);
    }

    // 29a-boundary — M1 review F-15: EXACTLY maxRows rows must also SUCCEED
    // (not just maxRows-1). Verified against bridge.js's actual code before
    // writing this assertion: the cap check runs BEFORE push, at the START
    // of each loop iteration, so exactly `sqlMaxRows` successful pushes
    // happen and the loop then ends (cursor exhausted) without ever
    // re-entering to trip the check — the at-cap row itself is not
    // rejected, only the FIRST row past it is (that's 29b). Documented here
    // and in test/README.md; the bridge itself was not changed to fit this.
    const atCap = await invoke('Probe', id, 'capProbe', ['rows', SQL_MAX_ROWS, 0]);
    if (atCap.status !== 200 || atCap.data?.error) {
        problems.push(`29a-boundary: HTTP ${atCap.status}: ${JSON.stringify(atCap.data)} (expected 200, ok:true)`);
    } else if (atCap.data?.result?.ok !== true || atCap.data.result.rowCount !== SQL_MAX_ROWS) {
        problems.push(
            `29a-boundary: result=${JSON.stringify(atCap.data?.result)} (expected ok:true, rowCount=${SQL_MAX_ROWS} — exactly at the cap must succeed)`
        );
    }

    // 29b — one row over the cap fails with sql_result_too_large, cap:'rows'.
    const overRows = await invoke('Probe', id, 'capProbe', ['rows', SQL_MAX_ROWS + 1, 0]);
    if (overRows.status !== 200) {
        problems.push(`29b: HTTP ${overRows.status}: ${JSON.stringify(overRows.data)} (expected 200 — capProbe catches the PDOException itself)`);
    } else {
        const r = overRows.data?.result;
        if (r?.ok !== false) {
            problems.push(`29b: result=${JSON.stringify(r)} (expected ok:false)`);
        } else if (r.code !== 'sql_result_too_large') {
            problems.push(`29b: code=${JSON.stringify(r.code)} (expected sql_result_too_large)`);
        } else if (r.cap !== 'rows') {
            // M1 review F-14 (MINOR, fixed): PRIMARY assertion — detail.cap,
            // read off BridgeSqlException::getDetail(), exactly as the spec
            // and test/README document. This used to be unreachable from
            // PHP at all (detail was dropped in SqlBridge::failure()).
            problems.push(`29b: cap=${JSON.stringify(r.cap)} (expected 'rows', from BridgeSqlException::getDetail())`);
        } else if (!/cap['":\s]+rows/i.test(r.message) && !r.message?.includes('ATOMS_SQL_MAX_ROWS')) {
            // SECONDARY assertion, kept: the message should still say so too.
            problems.push(`29b: message does not identify the rows cap: ${JSON.stringify(r.message)}`);
        }
    }

    // 29c — well under the row cap, but padded past the byte cap: proves the
    // two caps are independent (this must NOT come back as a rows overrun).
    const byteRows = Math.max(1, Math.min(SQL_MAX_ROWS - 1, 8));
    const padBytes = Math.ceil(SQL_MAX_RESULT_BYTES / byteRows) + 64;
    const overBytes = await invoke('Probe', id, 'capProbe', ['bytes', byteRows, padBytes]);
    if (overBytes.status !== 200) {
        problems.push(`29c: HTTP ${overBytes.status}: ${JSON.stringify(overBytes.data)} (expected 200)`);
    } else {
        const r = overBytes.data?.result;
        if (r?.ok !== false) {
            problems.push(`29c: result=${JSON.stringify(r)} (expected ok:false — byte cap should have fired)`);
        } else if (r.code !== 'sql_result_too_large') {
            problems.push(`29c: code=${JSON.stringify(r.code)} (expected sql_result_too_large)`);
        } else if (r.cap !== 'bytes') {
            // M1 review F-14: PRIMARY assertion, see 29b's comment.
            problems.push(`29c: cap=${JSON.stringify(r.cap)} (expected 'bytes', from BridgeSqlException::getDetail())`);
        } else if (!/cap['":\s]+bytes/i.test(r.message) && !r.message?.includes('ATOMS_SQL_MAX_RESULT_BYTES')) {
            // SECONDARY assertion, kept: the message should still say so too.
            problems.push(`29c: message does not identify the bytes cap: ${JSON.stringify(r.message)}`);
        }
    }

    // 29e — M1 review F-15: run mode (PDO::exec(), which discards rows) is
    // NOT subject to either cap — a statement generating far more than the
    // row cap must still succeed, proving the caps apply to rows mode only.
    const runMode = await invoke('Probe', id, 'capProbeRunMode', [SQL_MAX_ROWS * 3]);
    if (runMode.status !== 200 || runMode.data?.error) {
        problems.push(`29e: HTTP ${runMode.status}: ${JSON.stringify(runMode.data)} (expected 200, ok:true — run mode is uncapped by design)`);
    } else if (runMode.data?.result?.ok !== true) {
        problems.push(`29e: result=${JSON.stringify(runMode.data?.result)} (expected ok:true)`);
    }

    // 29d — the residency survived both failures (the pattern checks 7/8/10
    // use): a plain ping still returns 200.
    const ping = await invoke('Probe', id, 'ping', []);
    if (ping.status !== 200 || ping.data?.error) {
        problems.push(`29d: ping after both cap failures: HTTP ${ping.status}: ${JSON.stringify(ping.data)} (expected 200)`);
    }

    if (problems.length === 0) {
        pass(
            checkNum,
            name,
            `rows cap ${SQL_MAX_ROWS} (under-cap exact, over-cap sql_result_too_large/rows), ` +
                `bytes cap ${SQL_MAX_RESULT_BYTES} (over-cap sql_result_too_large/bytes, independent of the row cap), residency healthy`
        );
    } else {
        fail(checkNum, name, problems.join(' || '));
    }
});

// CHECK 30: pdo compatibility doc is current (M1 design §5.4).
//
// Re-uses check 28's already-fetched report (pdoMatrixReport, kept in a
// module-level variable the way the callback listener's records are),
// imports renderMatrixDoc from scripts/gen-pdo-matrix.mjs, reads
// test/pdo-expected.json and ../docs/pdo-compatibility.md, and
// byte-compares a fresh render against the committed doc. If check 28 did
// not produce a report — skipped, or failed before reaching that point —
// this FAILS rather than skipping: a stale doc is not excused by a missing
// run.
checks.push(async () => {
    const checkNum = 30;
    const name = 'pdo compatibility doc is current';

    if (!pdoMatrixReport) {
        fail(checkNum, name, 'no PDO differential report available (check 28 did not produce one) — cannot verify the doc is current');
        return;
    }

    const pinPath = join(__dirname, 'pdo-expected.json');
    const docPath = join(__dirname, '..', '..', 'docs', 'pdo-compatibility.md');

    let pins;
    try {
        pins = JSON.parse(readFileSync(pinPath, 'utf8'));
    } catch (err) {
        fail(checkNum, name, `could not read/parse ${pinPath}: ${err.message}`);
        return;
    }

    let committed;
    try {
        committed = readFileSync(docPath, 'utf8');
    } catch (err) {
        fail(checkNum, name, `could not read ${docPath}: ${err.message}`);
        return;
    }

    const fresh = renderMatrixDoc(pdoMatrixReport, pins);

    if (fresh === committed) {
        pass(checkNum, name, `${docPath} matches a fresh render of the differential report (${pdoMatrixReport.cases.length} cases)`);
        return;
    }

    const freshLines = fresh.split('\n');
    const committedLines = committed.split('\n');
    let firstDiff = -1;
    const maxLines = Math.max(freshLines.length, committedLines.length);
    for (let i = 0; i < maxLines; i++) {
        if (freshLines[i] !== committedLines[i]) {
            firstDiff = i + 1;
            break;
        }
    }

    fail(
        checkNum,
        name,
        `docs/pdo-compatibility.md is stale — first differing line ${firstDiff}: ` +
            `committed=${JSON.stringify(committedLines[firstDiff - 1] ?? '(missing)')} ` +
            `fresh=${JSON.stringify(freshLines[firstDiff - 1] ?? '(missing)')} — regenerate with ` +
            '`node scripts/gen-pdo-matrix.mjs > ../docs/pdo-compatibility.md` (from cloudflare/worker)'
    );
});

// ---------------------------------------------------------------- run

async function run() {
    console.log(`\nAtoms-on-Cloudflare MVP Conformance Suite`);
    console.log(`Base URL: ${baseUrl}`);
    console.log(`Skip: ${SKIP.length ? SKIP.join(', ') : 'none'}`);
    console.log(`Eviction wait: ${EVICTION_WAIT_MS}ms`);

    const keyFile = loadCallbackKeyFile();
    if (keyFile) {
        const port = parseInt(process.env.ATOMS_CALLBACK_PORT || '', 10) || keyFile.port;
        listener = new CallbackListener(keyFile.publicKey);
        await listener.start(port);
        console.log(`Callback listener: 127.0.0.1:${port} (checks 13-17 enabled)`);
    } else {
        console.log(`Callback listener: none (test/.callback-key.json missing — checks 13-17 will skip)`);
    }
    console.log(`Turn deadline (checks 15a/15b): ${TURN_DEADLINE_MS ? `${TURN_DEADLINE_MS}ms` : 'not set — 15 will skip'}`);
    console.log('');

    try {
        for (let i = 0; i < checks.length; i++) {
            const checkNum = i + 1;
            if (SKIP.includes(checkNum)) {
                console.log(`⊘ CHECK ${checkNum}: skipped`);
                continue;
            }

            try {
                await checks[i]();
            } catch (err) {
                failCount++;
                results.push({
                    checkNum,
                    name: '(unknown)',
                    status: 'FAIL',
                    msg: `${err.name}: ${err.message}`,
                });
                console.log(`✗ CHECK ${checkNum}: ${err.name}: ${err.message}`);
            }
        }
    } finally {
        if (listener) await listener.stop();
    }

    console.log('');
    console.log(`\nResults: ${passCount} passed, ${failCount} failed`);

    if (failCount > 0) {
        process.exit(1);
    }
}

run().catch(err => {
    console.error(`Fatal error: ${err.message}`);
    process.exit(1);
});
