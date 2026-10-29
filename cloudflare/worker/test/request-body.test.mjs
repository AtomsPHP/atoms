import test from 'node:test';
import assert from 'node:assert/strict';
import { registerHooks } from 'node:module';

// Exercise the real entry handler; only the DO implementation (which imports
// workerd and Wasm) is stubbed. The binding below records every dispatch.
registerHooks({
	resolve(specifier, context, nextResolve) {
		if (specifier === './atom-do.js') {
			return { url: 'data:text/javascript,export class AtomDurableObject {}', shortCircuit: true };
		}
		return nextResolve(specifier, context);
	},
});
const { default: worker } = await import('../src/index.js');
const limit = 64;

async function invoke(body, headers = {}) {
	const calls = [];
	const request = new Request('https://atoms.test/invoke/Counter/body/increment', {
		method: 'POST', headers, body, duplex: 'half',
	});
	const response = await worker.fetch(request, {
		ATOMS_SHARED_SECRET: 'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=',
		ATOMS_BEARER_AUTH: 'disabled',
		ATOMS_MAX_REQUEST_BYTES: String(limit),
		ATOMS: {
			idFromName: name => name,
			get: () => ({
				async fetch(url, options) {
					const call = JSON.parse(options.body);
					calls.push(call);
					return Response.json({ ok: true, result: call.args });
				},
			}),
		},
	});
	return { status: response.status, data: await response.json(), calls, request };
}

for (const char of ['a', '€', '😀']) {
	for (const size of [limit - 1, limit, limit + 1]) {
		for (const declared of [false, true]) {
			test(`${JSON.stringify(char)}: ${size} bytes, Content-Length ${declared ? 'present' : 'absent'}`, async () => {
				const available = size - Buffer.byteLength(JSON.stringify({ args: [''] }));
				const width = Buffer.byteLength(char);
				const arg = char.repeat(Math.floor(available / width)) + 'x'.repeat(available % width);
				const body = JSON.stringify({ args: [arg] });
				assert.equal(Buffer.byteLength(body), size);
				const result = await invoke(body, declared ? { 'Content-Length': String(size) } : {});
				assert.equal(result.status, size > limit ? 413 : 200);
				if (size > limit) {
					assert.equal(result.data.error.code, 'payload_too_large');
					assert.equal(result.data.error.retryable, false);
					assert.equal(result.calls.length, 0);
				} else {
					assert.deepEqual(result.data.result, [arg]);
				}
			});
		}
	}
}

test('UTF-8 sequences split across chunks decode intact', async () => {
	const arg = '€😀';
	const bytes = Buffer.from(JSON.stringify({ args: [arg] }));
	const stream = new ReadableStream({
		start(controller) {
			for (const byte of bytes) controller.enqueue(Uint8Array.of(byte));
			controller.close();
		},
	});
	const result = await invoke(stream);
	assert.equal(result.status, 200);
	assert.deepEqual(result.data.result, [arg]);
	assert.equal(stream.locked, false);
});

for (const cancelFails of [false, true]) {
	test(`overflow cancels before reading the remainder (cancel rejects: ${cancelFails})`, async () => {
		let reads = 0;
		let cancelled = false;
		const stream = new ReadableStream({
			pull(controller) {
				reads++;
				controller.enqueue(new Uint8Array(reads === 1 ? limit : 1).fill(32));
				if (reads === 3) controller.close();
			},
			cancel() {
				cancelled = true;
				if (cancelFails) throw new Error('cancel failed');
			},
		}, { highWaterMark: 0 });
		// An understated header cannot override the actual byte count.
		const result = await invoke(stream, { 'Content-Length': '1' });
		assert.equal(result.status, 413);
		assert.equal(result.data.error.code, 'payload_too_large');
		assert.equal(result.calls.length, 0);
		assert.equal(reads, 2);
		assert.equal(cancelled, true);
		assert.equal(stream.locked, false);
	});
}

test('oversized Content-Length is rejected without reading', async () => {
	let reads = 0;
	const stream = new ReadableStream({ pull() { reads++; } }, { highWaterMark: 0 });
	const result = await invoke(stream, { 'Content-Length': String(limit + 1) });
	assert.equal(result.status, 413);
	assert.equal(reads, 0);
	assert.equal(result.calls.length, 0);
});

test('empty bodies and invalid JSON retain their existing behavior', async () => {
	for (const body of [null, '', '  ']) {
		const result = await invoke(body);
		assert.equal(result.status, 200);
		assert.deepEqual(result.data.result, []);
	}
	const result = await invoke('{');
	assert.equal(result.status, 400);
	assert.equal(result.data.error.code, 'invalid_request');
	assert.equal(result.calls.length, 0);
});
