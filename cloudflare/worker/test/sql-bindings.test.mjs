import test from 'node:test';
import assert from 'node:assert/strict';
import { loadConfig } from '../src/config.js';
import { Bridge } from '../src/bridge.js';
import { INT64_TAG } from '../src/int64.js';

function makeBridge(env = {}) {
	const executed = [];
	const sql = {
		exec(query, ...bindings) {
			executed.push({ query, bindings });
			return {
				columnNames: ['ok'],
				rowsRead: 1,
				rowsWritten: 0,
				*[Symbol.iterator]() {
					yield { ok: 1 };
				},
			};
		},
	};
	const config = loadConfig(env);
	const bridge = new Bridge({
		ctx: { storage: { sql } },
		env,
		config,
		identityRef: () => ({ type: 'Counter', id: 'bindings-test' }),
		callbacks: null,
		ws: null,
		timers: null,
		inTransactionRef: () => false,
	});
	return { bridge, executed };
}

function selectWithPlaceholders(count) {
	return `SELECT ${Array(count).fill('?').join(', ')}`;
}

test('ATOMS_SQL_MAX_BINDINGS defaults to 100 and honors overrides', () => {
	assert.equal(loadConfig({}).sqlMaxBindings, 100);
	assert.equal(loadConfig({ ATOMS_SQL_MAX_BINDINGS: '42' }).sqlMaxBindings, 42);
});

test('opSqlExec allows exactly 100 bound parameters and rejects 101 by default', () => {
	const { bridge, executed } = makeBridge();

	const okReply = JSON.parse(
		bridge.handleSync(
			JSON.stringify({
				op: 'sql.exec',
				sql: selectWithPlaceholders(100),
				bindings: Array.from({ length: 100 }, (_, i) => `v${i}`),
				mode: 'rows',
			})
		)
	);
	assert.equal(okReply.ok, true);
	assert.equal(executed.length, 1);
	assert.equal(executed[0].bindings.length, 100);
	assert.equal(bridge.sqlCalls, 1);

	const failReply = JSON.parse(
		bridge.handleSync(
			JSON.stringify({
				op: 'sql.exec',
				sql: selectWithPlaceholders(101),
				bindings: Array.from({ length: 101 }, (_, i) => `v${i}`),
				mode: 'rows',
			})
		)
	);
	assert.equal(failReply.ok, false);
	assert.equal(failReply.error.code, 'invalid_request');
	assert.equal(failReply.error.message, 'sql.exec received 101 bindings, limit is 100');
	assert.equal(executed.length, 1, 'rejected queries must never reach ctx.storage.sql.exec');
	assert.equal(bridge.sqlCalls, 1);
});

test('opSqlExec counts bindings after inlineWideIntegers folds integer bindings', () => {
	const { bridge, executed } = makeBridge();

	// 100 string bindings + 25 tagged integer bindings => 100 bound parameters after inlining.
	const mixedOkBindings = [
		...Array.from({ length: 25 }, (_, i) => ({ [INT64_TAG]: String(i + 1) })),
		...Array.from({ length: 100 }, (_, i) => `s${i}`),
	];
	const mixedOk = JSON.parse(
		bridge.handleSync(
			JSON.stringify({
				op: 'sql.exec',
				sql: selectWithPlaceholders(125),
				bindings: mixedOkBindings,
				mode: 'rows',
			})
		)
	);
	assert.equal(mixedOk.ok, true);
	assert.equal(executed.length, 1);
	assert.equal(executed[0].bindings.length, 100);
	assert.match(executed[0].query, /^SELECT 1, 2, .*, 25, \?/);

	// 120 tagged integer bindings => 0 bound parameters after inlining.
	const allInts = JSON.parse(
		bridge.handleSync(
			JSON.stringify({
				op: 'sql.exec',
				sql: selectWithPlaceholders(120),
				bindings: Array.from({ length: 120 }, (_, i) => ({ [INT64_TAG]: String(i) })),
				mode: 'rows',
			})
		)
	);
	assert.equal(allInts.ok, true);
	assert.equal(executed.length, 2);
	assert.equal(executed[1].bindings.length, 0);

	// 101 string bindings + 5 tagged integer bindings => 101 bound parameters => rejected with 101, not 106.
	const mixedOver = JSON.parse(
		bridge.handleSync(
			JSON.stringify({
				op: 'sql.exec',
				sql: selectWithPlaceholders(106),
				bindings: [
					...Array.from({ length: 5 }, (_, i) => ({ [INT64_TAG]: String(i) })),
					...Array.from({ length: 101 }, (_, i) => `s${i}`),
				],
				mode: 'rows',
			})
		)
	);
	assert.equal(mixedOver.ok, false);
	assert.equal(mixedOver.error.code, 'invalid_request');
	assert.equal(mixedOver.error.message, 'sql.exec received 101 bindings, limit is 100');
	assert.equal(executed.length, 2);
});

test('ATOMS_SQL_MAX_BINDINGS override applies after integer inlining', () => {
	const { bridge, executed } = makeBridge({ ATOMS_SQL_MAX_BINDINGS: '2' });

	const okReply = JSON.parse(
		bridge.handleSync(
			JSON.stringify({
				op: 'sql.exec',
				sql: selectWithPlaceholders(5),
				bindings: [{ [INT64_TAG]: '10' }, 'a', { [INT64_TAG]: '20' }, 'b', { [INT64_TAG]: '30' }],
				mode: 'rows',
			})
		)
	);
	assert.equal(okReply.ok, true);
	assert.equal(executed.length, 1);
	assert.deepEqual(executed[0].bindings, ['a', 'b']);

	const failReply = JSON.parse(
		bridge.handleSync(
			JSON.stringify({
				op: 'sql.exec',
				sql: selectWithPlaceholders(4),
				bindings: [{ [INT64_TAG]: '10' }, 'a', 'b', 'c'],
				mode: 'rows',
			})
		)
	);
	assert.equal(failReply.ok, false);
	assert.equal(failReply.error.code, 'invalid_request');
	assert.equal(failReply.error.message, 'sql.exec received 3 bindings, limit is 2');
	assert.equal(executed.length, 1);
});
