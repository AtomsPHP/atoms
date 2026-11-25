import assert from 'node:assert/strict';
import test from 'node:test';
import { TransactionMachine } from '../src/bridge.js';
import { CallbackChannel } from '../src/callbacks.js';
import { loadConfig } from '../src/config.js';
import { serviceHttp } from '../src/http.js';

const origin = 'https://api.example.com';
const msg = { method: 'POST', url: origin + '/path?private=1', headers: [], body: null };
const config = loadConfig({ ATOMS_HTTP_ALLOWLIST: [origin] });

function window() {
	const callbacks = new CallbackChannel({ config, log() {} });
	return callbacks.beginTurn(config.turnDeadlineMs, callbacks.newCollector());
}

test('origin configuration is exact and a malformed entry closes the whole list', () => {
	assert.deepEqual(loadConfig({}).httpAllowlist, new Set());
	assert.deepEqual(loadConfig({ ATOMS_HTTP_ALLOWLIST: '["HTTPS://API.example.com:443/","https://api.example.com:8443"]' }).httpAllowlist,
		new Set([origin, origin + ':8443']));
	for (const entry of ['*', 'http://api.example.com', 'https://*.example.com', 'https://@api.example.com',
		origin + '?', origin + '#', origin + '/a/..', 'https://localhost', 'https://db.local',
		'https://127.0.0.1', 'https://[::1]', 'https://db.home.arpa', null]) {
		assert.equal(loadConfig({ ATOMS_HTTP_ALLOWLIST: [origin, entry] }).httpAllowlist, null);
	}
	for (const raw of ['{', '{}', '', null]) assert.equal(loadConfig({ ATOMS_HTTP_ALLOWLIST: raw }).httpAllowlist, null);
});

test('invalid wire data is refused before transport and before spending a slot', async () => {
	const budget = window();
	let sent = 0;
	for (const [fields, code] of [
		[{ body: '*' }, 'ATOMS-E118'], [{ body: 'AA=' }, 'ATOMS-E118'], [{ method: 'HEAD', body: '' }, 'ATOMS-E118'],
		[{ headers: [['Connection', 'close']] }, 'ATOMS-E117'], [{ headers: [['x', 'v\r']] }, 'ATOMS-E118'],
		[{ headers: [['x', 'v', 'third']] }, 'ATOMS-E118'], [{ url: origin + ':444' }, 'ATOMS-E116'],
		[{ body: 'A'.repeat(100) }, 'ATOMS-E121'],
	]) {
		const replies = [];
		await serviceHttp({ msg: { ...msg, ...fields }, reply: value => replies.push(JSON.parse(value)) }, budget,
			{ ...config, httpMaxRequestBytes: 80 }, () => { sent++; }, () => true);
		assert.equal(replies.length, 1);
		assert.equal(replies[0].error.code, code);
		assert.ok(!JSON.stringify(replies).includes('private'));
	}
	assert.equal(sent, 0);
	assert.equal(budget.httpRequests, 0);
});

test('the synchronous transaction drain independently refuses a parked HTTP request', () => {
	const replies = [];
	const parks = [
		{ msg: { ...msg, op: 'http.fetch' }, reply: frame => replies.push(JSON.parse(frame)) },
		{ msg: { op: 'tx.rollback' } },
	];
	const machine = new TransactionMachine({ config, host: { takePending: () => parks.shift() } });
	assert.equal(machine.drain().kind, 'rollback');
	assert.deepEqual(replies.map(reply => reply.error.code), ['tx_state']);
});

test('a late response to a discarded generation is canceled without resuming PHP', async () => {
	let resolveTransport;
	let canceled = false;
	let live = true;
	let replies = 0;
	const budget = window();
	const run = serviceHttp({ msg, reply: () => replies++ }, budget, config,
		() => new Promise(resolve => { resolveTransport = resolve; }), () => live);
	assert.equal(budget.httpRequests, 1);
	live = false;
	budget.httpController.abort();
	await run;
	resolveTransport(new Response(new ReadableStream({ cancel() { canceled = true; } })));
	await new Promise(resolve => setImmediate(resolve));
	assert.equal(canceled, true);
	assert.equal(replies, 0);
});
