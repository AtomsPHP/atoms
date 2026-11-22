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

function inListQuery(count) {
	return `SELECT 1 WHERE 1 IN (${Array(count).fill('?').join(', ')})`;
}

test('ATOMS_SQL_MAX_BINDINGS defaults to 100 and honors overrides', () => {
	assert.equal(loadConfig({}).sqlMaxBindings, 100);
	assert.equal(loadConfig({ ATOMS_SQL_MAX_BINDINGS: '42' }).sqlMaxBindings, 42);
});

test('opSqlExec enforces the 100-binding default after integer inlining', () => {
	const { bridge, executed } = makeBridge();

	// 100 string bindings + 1 tagged integer => 100 bound parameters after inlining.
	const okReply = JSON.parse(
		bridge.handleSync(
			JSON.stringify({
				op: 'sql.exec',
				sql: inListQuery(101),
				bindings: [{ [INT64_TAG]: '1' }, ...Array.from({ length: 100 }, (_, i) => `v${i}`)],
				mode: 'rows',
			})
		)
	);
	assert.equal(okReply.ok, true);
	assert.equal(executed.length, 1);
	assert.equal(executed[0].bindings.length, 100);
	assert.match(executed[0].query, /^SELECT 1 WHERE 1 IN \(1, \?/);
	assert.equal(bridge.sqlCalls, 1);

	// 101 string bindings + 1 tagged integer => 101 bound parameters => rejected with 101, not 102.
	const failReply = JSON.parse(
		bridge.handleSync(
			JSON.stringify({
				op: 'sql.exec',
				sql: inListQuery(102),
				bindings: [{ [INT64_TAG]: '1' }, ...Array.from({ length: 101 }, (_, i) => `v${i}`)],
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

test('ATOMS_SQL_MAX_BINDINGS override applies after integer inlining', () => {
	const { bridge, executed } = makeBridge({ ATOMS_SQL_MAX_BINDINGS: '2' });

	const okReply = JSON.parse(
		bridge.handleSync(
			JSON.stringify({
				op: 'sql.exec',
				sql: inListQuery(3),
				bindings: [{ [INT64_TAG]: '1' }, 'a', 'b'],
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
				sql: inListQuery(4),
				bindings: [{ [INT64_TAG]: '1' }, 'a', 'b', 'c'],
				mode: 'rows',
			})
		)
	);
	assert.equal(failReply.ok, false);
	assert.equal(failReply.error.code, 'invalid_request');
	assert.equal(failReply.error.message, 'sql.exec received 3 bindings, limit is 2');
	assert.equal(executed.length, 1);
});
