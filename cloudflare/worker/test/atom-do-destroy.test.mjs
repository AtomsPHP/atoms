#!/usr/bin/env node

/**
 * atom-do-destroy.test.mjs — unit tests for explicit Atom destruction,
 * zero-write empty DO probing, legacy constructor-only metadata cleanup,
 * partial-activation detection, route contract, and stale socket / alarm /
 * re-arm fencing across immediate same-ID reuse.
 *
 * Runs directly under Node >= 22.15 with `module.registerHooks` stubbing only
 * `cloudflare:workers`, `bundle.generated.js`, and `php-host.js` so the real
 * `src/index.js`, `src/atom-do.js`, `src/bridge.js`, `src/websockets.js`, and
 * `src/timers.js` execute unmodified.
 *
 *   node --test test/atom-do-destroy.test.mjs
 */

import test from 'node:test';
import assert from 'node:assert/strict';
import { registerHooks } from 'node:module';

const SECRET = 'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=';
const BEARER = 'Dx6RY9LS43pOQhM4PMdaUWx3lk9mfyiiJZFfJtvl9E0=';

const STUBS = {
	'atoms-test:cloudflare-workers': 'export class DurableObject { constructor(ctx, env) { this.ctx = ctx; this.env = env; } }\n',
	'atoms-test:bundle':
		'export default { manifest: { abi: { php: "8.3" }, atoms: { Counter: { websocket: false }, Room: { websocket: true }, Destructible: { websocket: true } } }, files: {} };\n',
	'atoms-test:php-host': [
		'export let bootCalls = 0;',
		'export function resetBootCalls() { bootCalls = 0; }',
		'export function bootPHP() { bootCalls++; throw new Error("unexpected bootPHP() call"); }',
		'export function composeBootCode() { return ""; }',
		'export function guestMemoryBytes() { return 0; }',
		'export function mkdirp() {}',
		'export function writeGuestFile() {}',
		'',
	].join('\n'),
};

registerHooks({
	resolve(specifier, context, nextResolve) {
		if (specifier === 'cloudflare:workers') {
			return { url: 'atoms-test:cloudflare-workers', shortCircuit: true };
		}
		if (specifier.endsWith('bundle.generated.js')) {
			return { url: 'atoms-test:bundle', shortCircuit: true };
		}
		if (specifier.endsWith('php-host.js')) {
			return { url: 'atoms-test:php-host', shortCircuit: true };
		}
		return nextResolve(specifier, context);
	},
	load(url, context, nextLoad) {
		if (Object.prototype.hasOwnProperty.call(STUBS, url)) {
			return { format: 'module', source: STUBS[url], shortCircuit: true };
		}
		return nextLoad(url, context);
	},
});

const phpHostStub = await import('../src/php-host.js');
const { default: worker } = await import('../src/index.js');
const { AtomDurableObject } = await import('../src/atom-do.js');
const { META_KEYS, META_TABLE, TIMERS_TABLE } = await import('../src/config.js');
const { buildAttachment, buildRetiredAttachment, readAttachment } = await import('../src/websockets.js');

/**
 * In-memory DurableObjectState double supporting the exact SQL statements,
 * alarm API, storage wipe, and WebSocket hibernation APIs used by Bridge,
 * TimersHost, WebSocketHost, and AtomDurableObject.
 */
function makeFakeCtx(opts = {}) {
	/** @type {Set<string>} */
	const tables = new Set();
	/** @type {Array<{type: string, name: string}>} */
	const extraObjects = [];
	/** @type {Map<string, string>} */
	const meta = new Map();
	/** @type {Map<string, number>} */
	const timers = new Map();

	/** @type {string[]} */
	const sqlReads = [];
	/** @type {string[]} */
	const sqlWrites = [];
	/** @type {Array<{op: string, at?: number}>} */
	const alarmOps = [];
	/** @type {any[]} */
	const sockets = [];
	/** @type {WeakMap<any, string[]>} */
	const socketTags = new WeakMap();

	let alarm = opts.alarm ?? null;
	let deleteAllCalls = 0;
	let deleteAllError = opts.deleteAllError ?? null;

	const sql = {
		get databaseSize() {
			return tables.size === 0 && extraObjects.length === 0 ? 0 : 4096;
		},
		exec(query, ...params) {
			const q = query.trim();

			if (/^CREATE TABLE IF NOT EXISTS\s+([A-Za-z0-9_]+)/i.test(q)) {
				const name = /^CREATE TABLE IF NOT EXISTS\s+([A-Za-z0-9_]+)/i.exec(q)[1];
				sqlWrites.push(q);
				tables.add(name);
				return { toArray: () => [] };
			}

			if (q === "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?") {
				sqlReads.push(q);
				return { toArray: () => (tables.has(params[0]) ? [{ 1: 1 }] : []) };
			}

			if (q === 'SELECT type, name FROM sqlite_master ORDER BY type ASC, name ASC') {
				sqlReads.push(q);
				const list = [
					...Array.from(tables).map((name) => ({ type: 'table', name })),
					...extraObjects,
				].sort((a, b) => (a.type === b.type ? a.name.localeCompare(b.name) : a.type.localeCompare(b.type)));
				return { toArray: () => list };
			}

			if (q === `SELECT key FROM ${META_TABLE} ORDER BY key ASC`) {
				sqlReads.push(q);
				if (!tables.has(META_TABLE)) throw new Error(`no such table: ${META_TABLE}`);
				return {
					toArray: () => Array.from(meta.keys()).sort().map((key) => ({ key })),
				};
			}

			if (q === `SELECT value FROM ${META_TABLE} WHERE key = ?`) {
				sqlReads.push(q);
				if (!tables.has(META_TABLE)) throw new Error(`no such table: ${META_TABLE}`);
				const v = meta.get(params[0]);
				return { toArray: () => (v === undefined ? [] : [{ value: v }]) };
			}

			if (q.startsWith(`INSERT INTO ${META_TABLE} (key, value)`)) {
				sqlWrites.push(q);
				if (!tables.has(META_TABLE)) throw new Error(`no such table: ${META_TABLE}`);
				meta.set(String(params[0]), String(params[1]));
				return { toArray: () => [] };
			}

			if (q === `SELECT COUNT(*) AS n FROM ${TIMERS_TABLE}`) {
				sqlReads.push(q);
				if (!tables.has(TIMERS_TABLE)) throw new Error(`no such table: ${TIMERS_TABLE}`);
				return { toArray: () => [{ n: timers.size }] };
			}

			if (q === `SELECT MIN(due_at_ms) AS due FROM ${TIMERS_TABLE}`) {
				sqlReads.push(q);
				if (!tables.has(TIMERS_TABLE)) throw new Error(`no such table: ${TIMERS_TABLE}`);
				if (timers.size === 0) return { toArray: () => [{ due: null }] };
				return { toArray: () => [{ due: Math.min(...timers.values()) }] };
			}

			if (q === `SELECT 1 FROM ${TIMERS_TABLE} WHERE name = ?`) {
				sqlReads.push(q);
				if (!tables.has(TIMERS_TABLE)) throw new Error(`no such table: ${TIMERS_TABLE}`);
				return { toArray: () => (timers.has(params[0]) ? [{ 1: 1 }] : []) };
			}

			if (q === `SELECT due_at_ms FROM ${TIMERS_TABLE} WHERE name = ?`) {
				sqlReads.push(q);
				if (!tables.has(TIMERS_TABLE)) throw new Error(`no such table: ${TIMERS_TABLE}`);
				const v = timers.get(params[0]);
				return { toArray: () => (v === undefined ? [] : [{ due_at_ms: v }]) };
			}

			if (q.startsWith(`SELECT name, due_at_ms FROM ${TIMERS_TABLE} WHERE due_at_ms <= ?`)) {
				sqlReads.push(q);
				if (!tables.has(TIMERS_TABLE)) throw new Error(`no such table: ${TIMERS_TABLE}`);
				const [nowMs, limit] = params;
				const due = Array.from(timers.entries())
					.filter(([, due_at_ms]) => due_at_ms <= nowMs)
					.sort((a, b) => (a[1] === b[1] ? a[0].localeCompare(b[0]) : a[1] - b[1]))
					.slice(0, limit)
					.map(([name, due_at_ms]) => ({ name, due_at_ms }));
				return { toArray: () => due };
			}

			if (q.startsWith(`INSERT INTO ${TIMERS_TABLE} (name, due_at_ms)`)) {
				sqlWrites.push(q);
				if (!tables.has(TIMERS_TABLE)) throw new Error(`no such table: ${TIMERS_TABLE}`);
				timers.set(String(params[0]), Number(params[1]));
				return { toArray: () => [] };
			}

			if (q === `DELETE FROM ${TIMERS_TABLE} WHERE name = ?`) {
				sqlWrites.push(q);
				if (!tables.has(TIMERS_TABLE)) throw new Error(`no such table: ${TIMERS_TABLE}`);
				timers.delete(String(params[0]));
				return { toArray: () => [] };
			}

			throw new Error(`FakeCtx SQL stub does not recognize query: ${q}`);
		},
	};

	const ctx = {
		id: opts.id ?? 'do-test-id',
		storage: {
			sql,
			async getAlarm() {
				return alarm;
			},
			async setAlarm(at) {
				alarm = Number(at);
				alarmOps.push({ op: 'setAlarm', at: alarm });
			},
			async deleteAlarm() {
				alarm = null;
				alarmOps.push({ op: 'deleteAlarm' });
			},
			async deleteAll() {
				deleteAllCalls++;
				if (deleteAllError) {
					throw deleteAllError;
				}
				tables.clear();
				extraObjects.length = 0;
				meta.clear();
				timers.clear();
			},
		},
		async blockConcurrencyWhile(fn) {
			return fn();
		},
		acceptWebSocket(ws, tags = []) {
			sockets.push(ws);
			socketTags.set(ws, [...tags]);
		},
		getWebSockets(tag) {
			if (tag === undefined) return [...sockets];
			return sockets.filter((ws) => (socketTags.get(ws) ?? []).includes(tag));
		},
		getTags(ws) {
			return [...(socketTags.get(ws) ?? [])];
		},
	};

	return {
		ctx,
		tables,
		extraObjects,
		meta,
		timers,
		sqlReads,
		sqlWrites,
		alarmOps,
		sockets,
		get alarm() {
			return alarm;
		},
		set alarm(v) {
			alarm = v;
		},
		get deleteAllCalls() {
			return deleteAllCalls;
		},
		set deleteAllError(e) {
			deleteAllError = e;
		},
		addSocket(ws, tags = []) {
			ctx.acceptWebSocket(ws, tags);
			return ws;
		},
		removeSocket(ws) {
			const i = sockets.indexOf(ws);
			if (i !== -1) sockets.splice(i, 1);
		},
	};
}

function makeFakeSocket(initialAttachment) {
	let attachment = initialAttachment;
	/** @type {Array<{code: number, reason: string}>} */
	const closes = [];
	/** @type {Array<string|ArrayBuffer>} */
	const sent = [];

	return {
		closes,
		sent,
		deserializeAttachment() {
			return attachment;
		},
		serializeAttachment(next) {
			attachment = next;
		},
		close(code, reason) {
			closes.push({ code, reason });
		},
		send(data) {
			sent.push(data);
		},
	};
}

function makeDo(fake, envOverrides = {}) {
	const env = {
		ATOMS_SHARED_SECRET: SECRET,
		ATOMS_BEARER_AUTH: 'disabled',
		ATOMS_DEBUG_ENDPOINTS: '1',
		...envOverrides,
	};
	const host = new AtomDurableObject(fake.ctx, env);
	const logs = [];
	host.log = (level, fields) => logs.push({ level, ...fields });
	return { host, logs, env };
}

function seedActiveAtom(fake, { type = 'Destructible', id = 'atom-1', incarnation = 'inc-a', constructions = '1' } = {}) {
	fake.tables.add(META_TABLE);
	fake.tables.add(TIMERS_TABLE);
	fake.meta.set(META_KEYS.type, type);
	fake.meta.set(META_KEYS.id, id);
	fake.meta.set(META_KEYS.constructions, constructions);
	if (incarnation !== null) {
		fake.meta.set(META_KEYS.incarnation, incarnation);
	} else {
		fake.meta.delete(META_KEYS.incarnation);
	}
}

// ---------------------------------------------------------------------------
// 1. Worker route contract (src/index.js)
// ---------------------------------------------------------------------------

test('DELETE /atoms/:type/:id enforces auth (no ticket carve-out), method, path, type/id, manifest, and envelope', async () => {
	const dispatched = [];
	const makeEnv = (overrides = {}, doReply = { ok: true, destroyed: true }) => ({
		ATOMS_SHARED_SECRET: SECRET,
		ATOMS_BEARER_AUTH: 'required',
		ATOMS: {
			idFromName: (name) => name,
			get: (id) => ({
				async fetch(url, options) {
					const call = JSON.parse(options.body);
					dispatched.push({ id, call });
					return Response.json(typeof doReply === 'function' ? doReply(call) : doReply);
				},
			}),
		},
		...overrides,
	});

	// Misconfigured Worker fails before routing.
	{
		const res = await worker.fetch(new Request('https://atoms.test/atoms/Counter/a', { method: 'DELETE' }), {
			ATOMS_BEARER_AUTH: 'disabled',
		});
		assert.equal(res.status, 500);
		assert.equal((await res.json()).error.code, 'misconfigured');
		assert.equal(dispatched.length, 0);
	}

	// Headerless DELETE is 401 even if ?ticket= is appended (ticket carve-out is /ws-only).
	{
		const res = await worker.fetch(
			new Request('https://atoms.test/atoms/Counter/a?ticket=v1.fake.sig', { method: 'DELETE' }),
			makeEnv()
		);
		assert.equal(res.status, 401);
		assert.equal((await res.json()).error.code, 'unauthenticated');
		assert.equal(dispatched.length, 0);
	}

	const authHeaders = { Authorization: `Bearer ${BEARER}` };

	// Method validation.
	for (const method of ['GET', 'POST', 'PUT']) {
		const res = await worker.fetch(
			new Request('https://atoms.test/atoms/Counter/a', { method, headers: authHeaders }),
			makeEnv()
		);
		assert.equal(res.status, 405, `${method} must be 405`);
		assert.equal((await res.json()).error.code, 'method_not_allowed');
	}

	// Path arity, bad type, bad id, unknown type — none may touch the DO.
	for (const [path, wantStatus, wantCode] of [
		['/atoms', 400, 'invalid_request'],
		['/atoms/Counter', 400, 'invalid_request'],
		['/atoms/Counter/a/extra', 400, 'invalid_request'],
		['/atoms/1BadType/a', 400, 'invalid_request'],
		['/atoms/Counter/bad%0Aid', 400, 'invalid_request'],
		['/atoms/NotDeployed/a', 404, 'unknown_atom_type'],
	]) {
		const res = await worker.fetch(
			new Request(`https://atoms.test${path}`, { method: 'DELETE', headers: authHeaders }),
			makeEnv()
		);
		assert.equal(res.status, wantStatus, path);
		assert.equal((await res.json()).error.code, wantCode, path);
	}
	assert.equal(dispatched.length, 0, 'invalid/unknown requests must never address a DO');

	// Valid DELETE forwards {kind:"destroy", type, id} and returns {"destroyed": true|false}.
	for (const flag of [true, false]) {
		dispatched.length = 0;
		const res = await worker.fetch(
			new Request('https://atoms.test/atoms/Counter/item%2042', { method: 'DELETE', headers: authHeaders }),
			makeEnv({}, { ok: true, destroyed: flag })
		);
		assert.equal(res.status, 200);
		assert.deepEqual(await res.json(), { destroyed: flag });
		assert.deepEqual(dispatched, [
			{ id: 'Counter\nitem 42', call: { kind: 'destroy', type: 'Counter', id: 'item 42' } },
		]);
	}

	// Non-boolean destroyed reply from DO is rejected as 500 internal.
	{
		const res = await worker.fetch(
			new Request('https://atoms.test/atoms/Counter/a', { method: 'DELETE', headers: authHeaders }),
			makeEnv({}, { ok: true, destroyed: 'yes' })
		);
		assert.equal(res.status, 500);
		assert.equal((await res.json()).error.code, 'internal');
	}

	// DO error envelopes pass through (redacting internal details).
	{
		const res = await worker.fetch(
			new Request('https://atoms.test/atoms/Counter/a', { method: 'DELETE', headers: authHeaders }),
			makeEnv({}, { ok: false, error: { code: 'internal', message: 'secret sqlite failure detail' } })
		);
		assert.equal(res.status, 500);
		const body = await res.json();
		assert.equal(body.error.code, 'internal');
		assert.equal(body.error.message, 'internal error');
	}
});

// ---------------------------------------------------------------------------
// 2. Zero-write constructor, read-only info(), absent destroy()
// ---------------------------------------------------------------------------

test('constructor, info(), and destroy() on a never-used ID leave storage empty and never boot PHP', async () => {
	phpHostStub.resetBootCalls();
	const fake = makeFakeCtx();
	// Local wrangler/Miniflare stores DO names in an internal `__miniflare_do_name`
	// table on every SQLite DO; inspectStorage() must treat it as platform-internal.
	fake.tables.add('__miniflare_do_name');
	const { host } = makeDo(fake);

	assert.equal(fake.sqlWrites.length, 0, 'constructor must perform zero SQL writes');
	assert.equal(fake.alarmOps.length, 0);
	assert.equal(host.constructions, 1);

	const infoBefore = await host.info('Destructible', 'missing-1');
	assert.equal(fake.sqlWrites.length, 0, 'info() must perform zero SQL writes');
	assert.equal(infoBefore.storage.exists, false);
	assert.deepEqual(infoBefore.storage.tables, []);
	assert.equal(infoBefore.storage.alarm_at_ms, null);
	assert.equal(infoBefore.php_booted, false);

	const res = await host.destroy('Destructible', 'missing-1');
	assert.deepEqual(res, { ok: true, destroyed: false });
	assert.equal(fake.sqlWrites.length, 0, 'destroy() on empty DO must not create tables');
	assert.equal(fake.deleteAllCalls, 0, 'empty DO needs no deleteAll()');
	assert.equal(fake.alarmOps.length, 0, 'empty DO needs no alarm mutations');
	assert.equal(phpHostStub.bootCalls, 0, 'absent destroy() must never boot PHP');
});

// ---------------------------------------------------------------------------
// 3. Legacy constructor-only metadata discarded as destroyed: false
// ---------------------------------------------------------------------------

test('legacy constructor-only metadata (__atoms_meta.constructions + empty host tables) is discarded with destroyed: false', async () => {
	phpHostStub.resetBootCalls();
	const fake = makeFakeCtx();
	fake.tables.add(META_TABLE);
	fake.tables.add(TIMERS_TABLE);
	fake.meta.set(META_KEYS.constructions, '4');

	const { host } = makeDo(fake);
	assert.equal(host.constructions, 5, 'constructor reads existing constructions read-only');
	assert.equal(fake.sqlWrites.length, 0);

	const res = await host.destroy('Destructible', 'legacy-empty');
	assert.deepEqual(res, { ok: true, destroyed: false });
	assert.equal(fake.deleteAllCalls, 1, 'scaffolding must be wiped');
	assert.equal(fake.tables.size, 0);
	assert.equal(fake.meta.size, 0);
	assert.equal(phpHostStub.bootCalls, 0);
});

// ---------------------------------------------------------------------------
// 4. Partial first activation without atom_type/atom_id -> destroyed: true
// ---------------------------------------------------------------------------

test('partial activation that persisted customer table, user_version, or timer row counts as destroyed: true', async () => {
	// Customer table without atom_type/atom_id.
	{
		const fake = makeFakeCtx();
		fake.tables.add(META_TABLE);
		fake.tables.add('customer_items');
		fake.meta.set(META_KEYS.constructions, '1');
		const { host } = makeDo(fake);

		const res = await host.destroy('Destructible', 'partial-table');
		assert.deepEqual(res, { ok: true, destroyed: true });
		assert.equal(fake.tables.size, 0);
		assert.equal(fake.deleteAllCalls, 1);
	}

	// Non-diagnostic meta key (user_version) without atom_type/atom_id.
	{
		const fake = makeFakeCtx();
		fake.tables.add(META_TABLE);
		fake.meta.set(META_KEYS.constructions, '1');
		fake.meta.set(META_KEYS.userVersion, '1');
		const { host } = makeDo(fake);

		const res = await host.destroy('Destructible', 'partial-uv');
		assert.deepEqual(res, { ok: true, destroyed: true });
		assert.equal(fake.tables.size, 0);
	}

	// Timer row without atom_type/atom_id.
	{
		const fake = makeFakeCtx();
		fake.tables.add(META_TABLE);
		fake.tables.add(TIMERS_TABLE);
		fake.meta.set(META_KEYS.constructions, '1');
		fake.timers.set('pending-boot', Date.now() + 5000);
		const { host } = makeDo(fake);

		const res = await host.destroy('Destructible', 'partial-timer');
		assert.deepEqual(res, { ok: true, destroyed: true });
		assert.equal(fake.timers.size, 0);
		assert.equal(fake.tables.size, 0);
	}
});

// ---------------------------------------------------------------------------
// 5. Warm resident teardown: hostile onDeactivation runs first, then cleanup
// ---------------------------------------------------------------------------

test('warm destroy runs onDeactivation, logs failure, wipes everything strictly after, and repeat DELETE returns false even while retired socket lingers', async () => {
	const fake = makeFakeCtx({ alarm: Date.now() + 10_000 });
	seedActiveAtom(fake, { type: 'Destructible', id: 'warm-1', incarnation: 'inc-warm' });
	fake.tables.add('destructible_state');
	fake.timers.set('existing-timer', Date.now() + 10_000);

	const ws = makeFakeSocket(buildAttachment('conn-1', ['lobby'], 'inc-warm'));
	fake.addSocket(ws, ['c:conn-1', 'ch:lobby']);

	const { host, logs } = makeDo(fake);
	host.identity = { type: 'Destructible', id: 'warm-1' };
	host.incarnation = 'inc-warm';

	let phpExited = false;
	let deactivationEnvelope = null;
	host.instance = {
		php: {
			exit() {
				phpExited = true;
			},
		},
		gen: 0,
		settled: false,
		error: null,
	};
	host.parkedTurn = {
		msg: { op: 'turn.await' },
		reply(raw) {
			deactivationEnvelope = JSON.parse(raw);
			// Simulate hostile onDeactivation: writes meta, schedules a timer, and throws.
			host.bridge.metaSet('hostile_key', 'must-not-survive');
			host.timers.opSchedule({ name: 'hostile-timer', due_at_ms: Date.now() + 100 });
			host.pending = {
				msg: {
					op: 'turn.await',
					result: {
						ok: false,
						error: { code: 'atom_exception', message: 'hostile boom', class: 'RuntimeException' },
					},
				},
				reply() {},
			};
		},
	};

	const first = await host.destroy('Destructible', 'warm-1');
	assert.deepEqual(first, { ok: true, destroyed: true });
	assert.deepEqual(deactivationEnvelope, { ok: true, kind: 'deactivate' });
	assert.equal(phpExited, true, 'resident PHP instance must be exited after deactivation');
	assert.equal(host.instance, null);
	assert.equal(host.parkedTurn, null);
	assert.equal(host.identity, null);
	assert.equal(host.incarnation, null);

	// Failure was logged, cleanup still completed completely.
	assert.equal(
		logs.filter((l) => l.msg === 'atoms.do.deactivation_failed').length,
		1,
		'deactivation failure must be logged'
	);
	assert.equal(fake.tables.size, 0, 'all tables including hostile writes must be wiped');
	assert.equal(fake.meta.size, 0);
	assert.equal(fake.timers.size, 0, 'hostile timer must be wiped');
	assert.equal(fake.alarm, null, 'platform alarm must be deleted');
	assert.equal(host.timers.touched, false);

	// Socket was retired and closed with 1000 "Atom destroyed".
	const retiredAtt = readAttachment(ws);
	assert.equal(retiredAtt?.retired, true);
	assert.deepEqual(ws.closes, [{ code: 1000, reason: 'Atom destroyed' }]);

	// While the closing socket still lingers in ctx.getWebSockets(), a repeat DELETE
	// must see retired sockets as non-existent and report destroyed: false.
	const second = await host.destroy('Destructible', 'warm-1');
	assert.deepEqual(second, { ok: true, destroyed: false });
});

// ---------------------------------------------------------------------------
// 6. Stale WebSocket events & broadcasts fenced after immediate same-ID reuse
// ---------------------------------------------------------------------------

test('stale socket events (retired, old incarnation, legacy, bad version) cannot touch a recreated Atom B', async () => {
	const fake = makeFakeCtx();
	seedActiveAtom(fake, { type: 'Destructible', id: 'reuse-1', incarnation: 'inc-A' });

	const wsRetired = makeFakeSocket(buildAttachment('c-ret', ['lobby'], 'inc-A'));
	fake.addSocket(wsRetired, ['c:c-ret', 'ch:lobby']);

	const { host } = makeDo(fake);
	const destroyed = await host.destroy('Destructible', 'reuse-1');
	assert.deepEqual(destroyed, { ok: true, destroyed: true });
	assert.equal(readAttachment(wsRetired)?.retired, true);

	// Immediately recreate Atom B on the same DO with a fresh incarnation UUID.
	seedActiveAtom(fake, { type: 'Destructible', id: 'reuse-1', incarnation: 'inc-B' });
	host.identity = { type: 'Destructible', id: 'reuse-1' };
	host.incarnation = 'inc-B';

	const wsB = makeFakeSocket(buildAttachment('c-B', ['lobby'], 'inc-B'));
	fake.addSocket(wsB, ['c:c-B', 'ch:lobby']);

	// Also inject sockets simulating edge cases: unretired old incarnation,
	// legacy pre-incarnation v1 socket (no `inc`), and unknown attachment v2.
	const wsOldInc = makeFakeSocket(buildAttachment('c-old', ['lobby'], 'inc-A'));
	const wsLegacy = makeFakeSocket({ v: 1, id: 'c-leg', ch: ['lobby'] });
	const wsV2 = makeFakeSocket({ v: 2, id: 'c-v2', ch: ['lobby'], inc: 'inc-B' });
	fake.addSocket(wsOldInc, ['c:c-old', 'ch:lobby']);
	fake.addSocket(wsLegacy, ['c:c-leg', 'ch:lobby']);
	fake.addSocket(wsV2, ['c:c-v2', 'ch:lobby']);

	const dispatchedTurns = [];
	host.ensureActive = async () => {
		dispatchedTurns.push('ensureActive');
	};
	host.runTurn = async (env) => {
		dispatchedTurns.push(env);
		return { ok: true, result: null };
	};

	for (const stale of [wsRetired, wsOldInc, wsLegacy, wsV2]) {
		await host.webSocketMessage(stale, 'late-frame');
		await host.webSocketClose(stale, 1000, 'late-close', true);
		await host.webSocketError(stale, new Error('late-error'));
	}

	assert.deepEqual(dispatchedTurns, [], 'no stale socket event may activate or dispatch onto Atom B');
	assert.equal(readAttachment(wsOldInc)?.retired, true);
	assert.equal(readAttachment(wsLegacy)?.retired, true);
	assert.equal(wsV2.closes[0]?.code, 1012);

	// Broadcast and socketFor on Atom B must reach ONLY wsB, never lingering stale sockets.
	const bcast = JSON.parse(host.ws.opBroadcast({ channel: 'lobby', frame: '{"hello":"B"}' }));
	assert.equal(bcast.ok, true);
	assert.equal(bcast.delivered, 1);
	assert.deepEqual(wsB.sent, ['{"hello":"B"}']);
	assert.deepEqual(wsRetired.sent, []);
	assert.deepEqual(wsOldInc.sent, []);
	assert.deepEqual(wsLegacy.sent, []);
	assert.equal(host.ws.socketFor('c-B'), wsB);
	assert.equal(host.ws.socketFor('c-ret'), null);
	assert.equal(host.ws.socketFor('c-old'), null);
	assert.equal(host.ws.socketFor('c-leg'), null);

	// Legitimate frame on wsB still delivers to Atom B.
	await host.webSocketMessage(wsB, 'hello-B');
	assert.equal(dispatchedTurns.length, 2); // ensureActive + ws.message
	assert.equal(dispatchedTurns[1].kind, 'ws.message');
	assert.equal(dispatchedTurns[1].payload, 'hello-B');
});

// ---------------------------------------------------------------------------
// 7. Legacy pre-incarnation Atom compatibility across upgrade until destroyed
// ---------------------------------------------------------------------------

test('legacy pre-incarnation Atom accepts v1 sockets without inc until destroyed, then fences them after reuse', async () => {
	const fake = makeFakeCtx();
	seedActiveAtom(fake, { type: 'Room', id: 'leg-room', incarnation: null });

	const wsLegacy = makeFakeSocket({ v: 1, id: 'leg-sock', ch: ['lobby'] });
	fake.addSocket(wsLegacy, ['c:leg-sock', 'ch:lobby']);

	const { host } = makeDo(fake);
	assert.equal(host.activeIncarnation(), null);
	assert.equal(host.ws.isActiveSocket(wsLegacy), true);

	const turns = [];
	host.ensureActive = async () => {};
	host.runTurn = async (env) => {
		turns.push(env);
		return { ok: true, result: null };
	};

	await host.webSocketMessage(wsLegacy, 'still-valid');
	assert.equal(turns.length, 1);
	assert.equal(turns[0].payload, 'still-valid');
});

// ---------------------------------------------------------------------------
// 8. runAlarm() on empty DO and mid-drain destroy() + immediate Atom B creation
// ---------------------------------------------------------------------------

test('runAlarm() on empty DO is a no-op with zero writes and zero PHP boots', async () => {
	phpHostStub.resetBootCalls();
	const fake = makeFakeCtx();
	const { host } = makeDo(fake);

	await host.runAlarm();
	assert.equal(fake.sqlWrites.length, 0);
	assert.equal(fake.alarmOps.length, 0);
	assert.equal(phpHostStub.bootCalls, 0);
});

test('runAlarm() interrupted mid-drain by destroy() and immediate Atom B creation stops without touching Atom B', async () => {
	const fake = makeFakeCtx({ alarm: Date.now() - 10 });
	seedActiveAtom(fake, { type: 'Destructible', id: 'alarm-race', incarnation: 'inc-A' });
	fake.timers.set('a-timer-1', Date.now() - 20);
	fake.timers.set('a-timer-2', Date.now() - 10);

	const { host } = makeDo(fake);
	const fired = [];

	let releaseSettle;
	let settleStarted;
	const settleStartedPromise = new Promise((r) => {
		settleStarted = r;
	});
	const settleGate = new Promise((r) => {
		releaseSettle = r;
	});

	host.ensureActive = async () => {};
	host.runTurn = async (env) => {
		fired.push({ inc: host.activeIncarnation(), name: env.name });
		return { ok: true, result: null };
	};
	host.settleDeliveries = async () => {
		settleStarted();
		await settleGate;
	};

	// Start draining Atom A's two due timers.
	const alarmPromise = host.runAlarm();
	await settleStartedPromise;

	// While Iteration 1 is settling outside the mutex, destroy Atom A AND
	// immediately recreate Atom B with its own due + future timers and alarm.
	const del = await host.destroy('Destructible', 'alarm-race');
	assert.deepEqual(del, { ok: true, destroyed: true });

	seedActiveAtom(fake, { type: 'Destructible', id: 'alarm-race', incarnation: 'inc-B' });
	host.identity = { type: 'Destructible', id: 'alarm-race' };
	host.incarnation = 'inc-B';
	const bDue = Date.now() - 5;
	const bFuture = Date.now() + 60_000;
	fake.timers.set('b-due', bDue);
	fake.timers.set('b-future', bFuture);
	fake.alarm = bFuture;
	const alarmOpsBeforeRelease = fake.alarmOps.length;

	// Let Atom A's alarm loop continue to Iteration 2.
	releaseSettle();
	await alarmPromise;

	// Only a-timer-1 ran; a-timer-2 was wiped; Atom B's timers & alarm are untouched.
	assert.deepEqual(fired, [{ inc: 'inc-A', name: 'a-timer-1' }]);
	assert.equal(fake.timers.get('b-due'), bDue, 'stale alarm loop must not consume Atom B timers');
	assert.equal(fake.timers.get('b-future'), bFuture);
	assert.equal(fake.alarm, bFuture, 'stale alarm loop must not overwrite or delete Atom B alarm');
	assert.equal(fake.alarmOps.length, alarmOpsBeforeRelease, 'stale alarm loop must not call rearmForAlarm()');
});

// ---------------------------------------------------------------------------
// 9. Post-turn rearmIfTouched() runs inside turn mutex before queued destroy()
// ---------------------------------------------------------------------------

test('post-turn timer re-arming runs inside the turn mutex strictly before a queued destroy()', async () => {
	const fake = makeFakeCtx();
	seedActiveAtom(fake, { type: 'Destructible', id: 'rearm-race', incarnation: 'inc-A' });

	const { host } = makeDo(fake);
	host.ensureActive = async () => {};

	let releaseTurn;
	let turnStarted;
	const turnStartedPromise = new Promise((r) => {
		turnStarted = r;
	});
	const turnGate = new Promise((r) => {
		releaseTurn = r;
	});

	const invokePromise = host.withCallbackWindow(
		{ type: 'Destructible', id: 'rearm-race' },
		null,
		async () => {
			turnStarted();
			await turnGate;
			host.timers.opSchedule({ name: 'late-rearm', due_at_ms: Date.now() + 5000 });
			return { ok: true, result: 'done' };
		},
		async () => {}
	);

	await turnStartedPromise;

	// Queue destroy() while the turn is still executing inside the mutex.
	const destroyPromise = host.destroy('Destructible', 'rearm-race');

	releaseTurn();
	const [invRes, delRes] = await Promise.all([invokePromise, destroyPromise]);

	assert.equal(invRes.ok, true);
	assert.deepEqual(delRes, { ok: true, destroyed: true });
	assert.equal(fake.alarm, null, 'destroy() must wipe the alarm after post-turn rearm completed');
	assert.equal(fake.timers.size, 0);
	// Verify ordering: setAlarm ran BEFORE deleteAlarm.
	const ops = fake.alarmOps.map((o) => o.op);
	assert.deepEqual(ops, ['setAlarm', 'deleteAlarm']);
});

// ---------------------------------------------------------------------------
// 10. Storage wipe failure surfaces as an error (never {destroyed: true})
// ---------------------------------------------------------------------------

test('storage deleteAll() failure surfaces as an internal error rather than returning destroyed: true', async () => {
	const fake = makeFakeCtx({ deleteAllError: new Error('disk full') });
	seedActiveAtom(fake, { type: 'Destructible', id: 'wipe-fail', incarnation: 'inc-A' });

	const { host } = makeDo(fake);

	const response = await host.fetch(
		new Request('https://atoms.internal/do', {
			method: 'POST',
			headers: { 'content-type': 'application/json' },
			body: JSON.stringify({ kind: 'destroy', type: 'Destructible', id: 'wipe-fail' }),
		})
	);
	const env = await response.json();
	assert.equal(env.ok, false);
	assert.equal(env.error.code, 'internal');
	assert.match(env.error.message, /disk full/);
});

// ---------------------------------------------------------------------------
// 11. Queued alarm arriving while A's teardown is in flight and B is queued
// ---------------------------------------------------------------------------

test('queued runAlarm() delivered during A teardown with B reuse queued ahead never binds to B', async () => {
	const fake = makeFakeCtx({ alarm: Date.now() - 10 });
	seedActiveAtom(fake, { type: 'Destructible', id: 'alarm-queued', incarnation: 'inc-A' });
	fake.timers.set('a-due', Date.now() - 20);

	const { host } = makeDo(fake);
	host.identity = { type: 'Destructible', id: 'alarm-queued' };
	host.incarnation = 'inc-A';

	// Hold A's onDeactivation() open on the turn mutex.
	let deactEntered;
	const deactEnteredPromise = new Promise((r) => {
		deactEntered = r;
	});
	let releaseDeact;
	const deactGate = new Promise((r) => {
		releaseDeact = r;
	});

	host.instance = { php: { exit() {} }, gen: 0, settled: false, error: null };
	host.parkedTurn = { msg: { op: 'turn.await' }, reply() {} };
	const firedTimers = [];
	const origRunTurn = host.runTurn.bind(host);
	host.runTurn = async (env) => {
		if (env.kind === 'deactivate') {
			deactEntered();
			await deactGate;
			return { ok: true, result: null };
		}
		if (env.kind === 'timer') {
			firedTimers.push({ inc: host.activeIncarnation(), name: env.name });
			return { ok: true, result: null };
		}
		return origRunTurn(env);
	};
	host.ensureActive = async () => {};

	// 1. Start A's destroy() and wait until it holds the mutex inside onDeactivation().
	const destroyPromise = host.destroy('Destructible', 'alarm-queued');
	await deactEnteredPromise;

	// 2. Queue B's explicit reuse behind A's destroy() on the turn mutex.
	const bDue = Date.now() - 5;
	const bFuture = Date.now() + 60_000;
	const reuseBPromise = host.enqueue(async () => {
		seedActiveAtom(fake, { type: 'Destructible', id: 'alarm-queued', incarnation: 'inc-B' });
		host.identity = { type: 'Destructible', id: 'alarm-queued' };
		host.incarnation = 'inc-B';
		fake.timers.set('b-due', bDue);
		fake.timers.set('b-future', bFuture);
		fake.alarm = bFuture;
	});

	// 3. Deliver A's already-running platform alarm BEFORE releasing teardown.
	const staleAlarmPromise = host.runAlarm();

	// 4. Release A's teardown and let everything settle.
	releaseDeact();
	const delRes = await destroyPromise;
	await reuseBPromise;
	await staleAlarmPromise;

	assert.deepEqual(delRes, { ok: true, destroyed: true });
	assert.deepEqual(firedTimers, [], 'A queued alarm must never dispatch B-due on inc-B');
	assert.equal(fake.timers.get('b-due'), bDue, 'B due timer must remain untouched');
	assert.equal(fake.timers.get('b-future'), bFuture);
	assert.equal(fake.alarm, bFuture, 'B alarm must remain untouched');
});

// ---------------------------------------------------------------------------
// 12. Literal reserved-prefix matching in inspectStorage()
// ---------------------------------------------------------------------------

test('inspectStorage() matches reserved prefixes literally and excludes only __miniflare_do_name (sqlitecache, acf_users, abminiflareX, __miniflare_reports count as customer data)', async () => {
	for (const tableName of ['sqlitecache', 'acf_users', 'abminiflareX', '__miniflare_reports']) {
		const fake = makeFakeCtx();
		fake.tables.add('__miniflare_do_name');
		fake.tables.add('_cf_KV');
		fake.tables.add('sqlite_sequence');
		fake.tables.add(META_TABLE);
		fake.tables.add(tableName);
		fake.meta.set(META_KEYS.constructions, '1');

		const { host } = makeDo(fake);
		const info = await host.info('Destructible', `lit-${tableName}`);
		assert.equal(info.storage.exists, true, `${tableName} must count as customer data`);
		assert.deepEqual(info.storage.user_tables, [tableName]);

		const res = await host.destroy('Destructible', `lit-${tableName}`);
		assert.deepEqual(res, { ok: true, destroyed: true }, `${tableName} must produce destroyed: true`);
	}
});

// ---------------------------------------------------------------------------
// 13. Destruction owns the 1000 / "Atom destroyed" close frame during onDeactivation
// ---------------------------------------------------------------------------

test('guest onDeactivation() calling ws.close(4001, ...) cannot override 1000 "Atom destroyed"', async () => {
	const fake = makeFakeCtx();
	seedActiveAtom(fake, { type: 'Destructible', id: 'close-own', incarnation: 'inc-A' });

	const ws = makeFakeSocket(buildAttachment('conn-own', ['lobby'], 'inc-A'));
	fake.addSocket(ws, ['c:conn-own', 'ch:lobby']);

	const { host } = makeDo(fake);
	host.identity = { type: 'Destructible', id: 'close-own' };
	host.incarnation = 'inc-A';
	host.ws.noteSocket('conn-own', ws);
	host.instance = { php: { exit() {} }, gen: 0, settled: false, error: null };
	host.parkedTurn = {
		msg: { op: 'turn.await' },
		reply() {
			// Guest tries to close with a custom code/reason during onDeactivation().
			const closeReply = JSON.parse(
				host.ws.opClose({ conn: 'conn-own', code: 4001, reason: 'guest deactivation' })
			);
			assert.equal(closeReply.ok, true);
			assert.equal(closeReply.already_gone, true, 'socket must already be retired before onDeactivation runs');
			host.pending = {
				msg: { op: 'turn.await', result: { ok: true, result: null } },
				reply() {},
			};
		},
	};

	const res = await host.destroy('Destructible', 'close-own');
	assert.deepEqual(res, { ok: true, destroyed: true });
	assert.equal(ws.closes[0]?.code, 1000);
	assert.equal(ws.closes[0]?.reason, 'Atom destroyed');
	assert.equal(
		ws.closes.some((c) => c.code === 4001),
		false,
		'guest close(4001) must never be sent on the wire'
	);
});

// ---------------------------------------------------------------------------
// 14. Consecutive cold reconstructions of an existing Atom increment constructions
// ---------------------------------------------------------------------------

test('consecutive cold reconstructions of an existing Atom persist constructions 1 -> 2 -> 3 without booting PHP', async () => {
	phpHostStub.resetBootCalls();
	const fake = makeFakeCtx();
	seedActiveAtom(fake, { type: 'Counter', id: 'cold-count', incarnation: 'inc-A', constructions: '1' });

	const { host: cold1 } = makeDo(fake);
	assert.equal(cold1.constructions, 2);
	assert.equal(fake.meta.get(META_KEYS.constructions), '2');

	const { host: cold2 } = makeDo(fake);
	assert.equal(cold2.constructions, 3);
	assert.equal(fake.meta.get(META_KEYS.constructions), '3');
	assert.equal(phpHostStub.bootCalls, 0);
});
