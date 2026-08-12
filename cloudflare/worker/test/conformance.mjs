#!/usr/bin/env node

/**
 * Conformance suite for Atoms-on-Cloudflare MVP.
 *
 * Runs 22 conformance checks against a live worker URL per the spec: 1-12
 * against the Worker alone, 13-17 against the callback channel (app()/
 * dispatch()), for which this suite itself plays the monolith — a
 * `node:http` listener bound to 127.0.0.1 that verifies Ed25519 signatures
 * with `node:crypto` (design doc §10) — and 18-22 against the WebSocket seam
 * and `broadcast()` (design doc §10), using Node's built-in global
 * `WebSocket` (M14) so no `ws` dependency is ever added to a GPL-assembled
 * package.json.
 *
 * Config via env:
 *   ATOMS_BASE_URL (required)
 *   ATOMS_APP_KEY (optional bearer token)
 *   ATOMS_EVICTION_WAIT_MS (default 12500)
 *   ATOMS_CALLBACK_PORT (default: the port recorded in test/.callback-key.json)
 *   ATOMS_TURN_DEADLINE_MS (required for checks 15a/15b; must match the value
 *     the Worker was started with — never defaulted here, so no capacity
 *     number is written into the suite)
 *   ATOMS_SKIP=n,m (comma-separated check numbers to skip)
 */

import { createPublicKey, verify as verifyEd25519 } from 'node:crypto';
import { createServer, request as httpRequest } from 'node:http';
import { existsSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const BASE_URL = process.env.ATOMS_BASE_URL;
const APP_KEY = process.env.ATOMS_APP_KEY;
const EVICTION_WAIT_MS = parseInt(process.env.ATOMS_EVICTION_WAIT_MS || '12500');
const TURN_DEADLINE_MS = process.env.ATOMS_TURN_DEADLINE_MS ? parseInt(process.env.ATOMS_TURN_DEADLINE_MS, 10) : null;
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
 * callback listener, no configured turn deadline). Not a failure: it must not
 * fail a run against a Worker that legitimately has no callback channel.
 */
function skip(checkNum, name, msg = '') {
    results.push({ checkNum, name, status: 'SKIP', msg });
    console.log(`⊘ CHECK ${checkNum}: ${name} — skipped${msg ? ` (${msg})` : ''}`);
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
 * A plain HTTP request carrying `Upgrade: websocket` but never completing the
 * actual WebSocket handshake (no `Sec-WebSocket-Key`) — for asserting that a
 * BAD upgrade is refused with an ordinary JSON error response before any DO
 * is touched (design doc §2). Built on `node:http` rather than `fetch()`
 * because `Upgrade`/`Connection` are the two headers a spec-compliant fetch
 * implementation may refuse to let a caller set by hand.
 *
 * @param {string} path
 * @returns {Promise<{status: number, data: any}>}
 */
function wsHandshakeAttempt(path) {
    return new Promise((resolve, reject) => {
        const url = new URL(path, baseUrl);
        const headers = { Upgrade: 'websocket', Connection: 'Upgrade' };
        if (APP_KEY) headers.Authorization = `Bearer ${APP_KEY}`;
        const req = httpRequest(url, { method: 'GET', headers }, (res) => {
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
                resolve({ status: res.statusCode, data });
            });
        });
        req.on('error', reject);
        req.end();
    });
}

/**
 * Open a WebSocket to the worker's `/ws` route and wait for it to connect.
 * Node 22's global `WebSocket` accepts `{headers: {Authorization: ...}}`
 * (M14, an undici extension), so this works identically whether
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
 * node:crypto can import it. Mirrors the PKCS8 trick on the signing side
 * (design doc §6.1) — same idea, the public-key encoding.
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
 * literally true (design doc §10.1).
 *
 * Inherits the opaque-body invariant (design doc §10.3): the wide-integer
 * argument to `echoBig` is extracted and echoed back TEXTUALLY, via regex on
 * the raw body, never through `JSON.parse` — which would silently round an
 * int64-range value the same way a buggy host implementation would, making
 * the check meaningless. Everything else (headers, signature, kind, job
 * class, argument names) is parsed normally.
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

        this.records.push({
            kind,
            headers: { timestamp, nonce, signatureB64 },
            rawText,
            parsed,
            signatureValid,
            timestampFresh,
            nonceValid,
            nonceRepeated,
        });

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
                res.writeHead(200, { 'content-type': 'application/json' });
                res.end(`{"result":${literal}}`);
                return;
            }
            res.writeHead(422, { 'content-type': 'application/json' });
            res.end(JSON.stringify({ error: { code: 'ATOMS-E066', message: `no fixture handler for method ${method}` } }));
            return;
        }

        if (kind === 'job') {
            res.writeHead(200, { 'content-type': 'application/json' });
            res.end('{"queued":true}');
            return;
        }

        res.writeHead(422, { 'content-type': 'application/json' });
        res.end(JSON.stringify({ error: { code: 'invalid_request', message: `unknown X-Atoms-Kind ${JSON.stringify(kind)}` } }));
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
        skip(checkNum, name, 'no callback listener — test/.callback-key.json is missing; run via `npm run dev:callback`');
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
        skip(checkNum, name, 'no callback listener');
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
        skip(checkNum, name, 'no callback listener');
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
    const afterA = await invoke('Vault', idA, 'getBig', ['anything']);
    if (afterA.status !== 200 || afterA.data?.error) {
        problems.push(`15a: next invoke on the same Atom failed: ${JSON.stringify(afterA.data)}`);
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

    if (problems.length === 0) {
        pass(
            checkNum,
            name,
            `15a: 504/turn_deadline_exceeded after ${elapsed}ms, residency healthy; 15b: 200 with 1 request`
        );
    } else {
        fail(checkNum, name, problems.join('; '));
    }
});

// CHECK 16: dispatch() delivered, signed, kind=job
checks.push(async () => {
    const checkNum = 16;
    const name = 'dispatch() delivered, signed, kind=job';
    if (!listener) {
        skip(checkNum, name, 'no callback listener');
        return;
    }

    const id = atomId('notify');
    listener.clear();

    const res = await invoke('Counter', id, 'notify', ['hello-16']);
    if (res.status !== 200 || res.data?.error) {
        fail(checkNum, name, `notify failed: ${JSON.stringify(res.data)}`);
        return;
    }
    // By the time the HTTP response was read, the delivery had already been
    // recorded — settleTurn() awaits it before the turn's response goes out
    // (design doc §4.1).
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

    pass(checkNum, name, `kind=job, signed, args keyed by promoted property name, delivered before the response`);
});

// CHECK 17: dispatch() transaction semantics
checks.push(async () => {
    const checkNum = 17;
    const name = 'dispatch() transaction semantics (buffer/drop/deliver)';
    if (!listener) {
        skip(checkNum, name, 'no callback listener');
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
        if (res.status !== 500 || res.data?.error?.code !== 'atom_exception') {
            problems.push(`notifyThenThrow: status=${res.status}/${res.data?.error?.code} (expected 500/atom_exception)`);
        }
        if (listener.records.length !== 1) {
            problems.push(
                `notifyThenThrow: listener recorded ${listener.records.length} requests (expected 1 — delivered despite the throw)`
            );
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

    // The invocable_method() denylist (design doc §4): onConnect/onMessage/
    // onDisconnect are public on Atom but must be unreachable via POST
    // /invoke — reachable ONLY through a socket.
    const viaInvoke = await invoke('Room', id, 'onMessage', []);
    if (viaInvoke.status !== 404 || viaInvoke.data?.error?.code !== 'method_not_found') {
        problems.push(
            `POST /invoke .../onMessage gave ${viaInvoke.status}/${viaInvoke.data?.error?.code} ` +
                '(expected 404/method_not_found)'
        );
    }

    if (problems.length === 0) {
        pass(
            checkNum,
            name,
            'welcome frame observed with full params, bad upgrades refused pre-DO, onMessage unreachable via invoke'
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
        // content-based opcode rule (design doc §5 — text iff valid UTF-8)
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
                'an empty-channel broadcast is a no-op, not an error'
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
