import assert from 'node:assert/strict';
import test from 'node:test';
import { TypePhpTransactions } from '../src/typephp-transactions.js';

function harness(operations, { maxSteps = 8, commitFailure = false } = {}) {
	const events = [];
	const rows = ['before'];
	const queue = [...operations];
	let pending = null;
	let restored = null;
	const machine = new TypePhpTransactions({
		ctx: { storage: { async transaction(fn) {
			const saved = [...rows];
			events.push('transaction.begin');
			try {
				await fn();
				if (commitFailure) throw new Error('storage commit failed');
				events.push('transaction.commit');
			} catch (error) {
				rows.splice(0, rows.length, ...saved);
				events.push('transaction.rollback');
				throw error;
			}
		} } },
		config: { maxTxParkSteps: maxSteps, parkWaitTimeoutMs: 50 },
		host: {
			takePending() { const next = pending; pending = null; return next; },
			async waitForPark() {
				// A promise continuation models a JSPI resume, which the synchronous
				// transaction machine cannot service.
				await Promise.resolve();
				const op = queue.shift();
				if (op === 'fatal' || op === undefined) return null;
				if (op === 'write') { rows.push('inside'); return this.waitForPark(); }
				return park(op);
			},
			restorePending(value) { restored = value; },
		},
		callbacks: {
			onTransactionCommit() { events.push('dispatch.release'); },
			onTransactionRollback() { events.push('dispatch.discard'); },
		},
	});
	function park(op) {
		return { msg: { op, result: { ok: true, result: 'uncommitted' } }, reply(raw) {
			const result = JSON.parse(raw);
			events.push({ op, result, open: machine.open, rows: [...rows] });
		} };
	}
	return { machine, events, rows, start: () => machine.begin(park('tx.begin')), restored: () => restored };
}

test('commit replies and releases dispatches only after storage commits', async () => {
	const h = harness(['write', 'tx.commit']);
	await h.start();
	assert.deepEqual(h.rows, ['before', 'inside']);
	assert.deepEqual(h.events.slice(-3), [
		'transaction.commit', 'dispatch.release',
		{ op: 'tx.commit', result: { ok: true, committed: true }, open: false, rows: ['before', 'inside'] },
	]);
});

test('rollback discards writes and dispatches before resuming the guest', async () => {
	const h = harness(['write', 'tx.rollback']);
	await h.start();
	assert.deepEqual(h.rows, ['before']);
	assert.deepEqual(h.events.slice(-3), [
		'transaction.rollback', 'dispatch.discard',
		{ op: 'tx.rollback', result: { ok: true, rolledBack: true }, open: false, rows: ['before'] },
	]);
});

test('a fatal guest or storage failure rolls back and never acknowledges commit', async () => {
	for (const h of [harness(['write', 'fatal']), harness(['write', 'tx.commit'], { commitFailure: true })]) {
		await assert.rejects(h.start());
		assert.deepEqual(h.rows, ['before']);
		assert.equal(h.machine.open, false);
		assert.equal(h.events.includes('dispatch.release'), false);
		assert.equal(h.events.some(event => event?.result?.committed), false);
		assert.equal(h.events.at(-1), 'dispatch.discard');
	}
});

test('an abandoned transaction returns an error turn without resuming it', async () => {
	const h = harness(['write', 'turn.await']);
	await h.start();
	assert.deepEqual(h.rows, ['before']);
	assert.equal(h.restored().msg.result.ok, false);
	assert.equal(h.restored().msg.result.error.code, 'internal');
	assert.equal(h.events.some(event => event?.op === 'turn.await'), false);
});

test('callbacks and nested transactions remain refused inside a transaction', async () => {
	const h = harness(['write', 'app.call', 'tx.begin', 'tx.rollback']);
	await h.start();
	for (const op of ['app.call', 'tx.begin']) {
		const reply = h.events.find(event => event?.op === op && event?.result?.ok === false);
		assert.equal(reply.result.error.code, 'tx_state');
		assert.equal(reply.open, true);
	}
	assert.deepEqual(h.rows, ['before']);
});

test('the configured park limit rolls back an unfinished transaction', async () => {
	const h = harness(['write', 'app.call', 'app.call', 'tx.commit'], { maxSteps: 2 });
	await assert.rejects(h.start(), /ATOMS_MAX_TX_PARK_STEPS/);
	assert.deepEqual(h.rows, ['before']);
	assert.equal(h.machine.open, false);
	assert.equal(h.events.filter(event => event?.op === 'app.call').length, 1,
		'the final park stays suspended so its guest continuation cannot outlive rollback');
});
