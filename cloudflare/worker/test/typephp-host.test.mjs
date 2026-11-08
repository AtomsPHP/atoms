#!/usr/bin/env node

import assert from 'node:assert/strict';
import { registerHooks } from 'node:module';
import test from 'node:test';

// Exercise the same browser WASI implementation Wrangler selects. The shim's
// Node condition otherwise selects its native filesystem and worker-thread IO.
registerHooks({
	resolve(specifier, context, nextResolve) {
		if (specifier === '@bytecodealliance/preview2-shim' || specifier.startsWith('@bytecodealliance/preview2-shim/')) {
			return nextResolve(specifier, { ...context, conditions: context.conditions.filter(condition => condition !== 'node') });
		}
		return nextResolve(specifier, context);
	},
});

const { bootTypePhp } = await import('../src/typephp-host.js');
const encoder = new TextEncoder();
const decoder = new TextDecoder();

function frame(message) {
	return encoder.encode(`\x1eATOMS:${encoder.encode(message).length}\n${message}`);
}

async function line(stream) {
	let text = '';
	while (!text.endsWith('\n')) text += decoder.decode(await stream.blockingRead(1n));
	return text.slice(0, -1);
}

function fixture(run, options = {}) {
	return bootTypePhp({
		maxFrameBytes: 1024,
		coreModules: {},
		onSync: () => '{"ok":true}',
		onPark: (_message, reply) => reply('{"ok":true}'),
		instantiate: async (_module, imports) => ({
			run: { run: () => run({
				input: imports['wasi:cli/stdin'].getStdin(),
				output: imports['wasi:cli/stdout'].getStdout(),
				root: imports['wasi:filesystem/preopens'].getDirectories()[0][0],
			}) },
		}),
		...options,
	});
}

test('isolates files, boot inputs and deferred replies across concurrent residencies', async () => {
	const pending = [];
	const hosts = ['one', 'two'].map(name => {
		const host = fixture(async ({ input, output, root }) => {
			assert.deepEqual(JSON.parse(await line(input)), { name });
			const file = root.openAt({}, 'app/name.txt', {}, { read: true });
			assert.equal(decoder.decode(file.read(100n, 0n)[0]), name);
			// Every possible byte boundary, including inside the UTF-8 codepoint.
			for (const byte of frame('!"é"')) output.blockingWriteAndFlush(new Uint8Array([byte]));
			assert.equal(await line(input), name);
			output.blockingWriteAndFlush(frame('~"wait"'));
			assert.equal(await line(input), name + '-reply');
		}, {
			onSync: message => { assert.equal(message, '"é"'); return name; },
			onPark: (_message, reply) => pending.push(() => reply(name + '-reply')),
		});
		host.writeFile('/app/name.txt', name);
		return host;
	});
	const runs = hosts.map((host, index) => host.run({ bootPayload: { name: ['one', 'two'][index] } }));
	while (pending.length !== 2) await new Promise(resolve => setImmediate(resolve));
	// Resolve in the reverse order to expose accidental shared stdin state.
	pending[1]();
	pending[0]();
	await Promise.all(runs);
	for (const host of hosts) assert.deepEqual(host.__atoms, { syncCalls: 1, parkCalls: 1 });
});

test('keeps ordinary stdout and partial marker tails, tolerating logging failures', async () => {
	const logged = [];
	const host = fixture(async ({ input, output }) => {
		await line(input);
		output.blockingWriteAndFlush(encoder.encode('message\n\x1eATOM'));
	}, { onLog: bytes => logged.push(decoder.decode(bytes)) });
	await host.run({ bootPayload: {} });
	assert.equal(logged.join(''), 'message\n\x1eATOM');
	const badLogger = fixture(async ({ input, output }) => {
		await line(input);
		output.blockingWriteAndFlush(encoder.encode('message'));
	}, { onLog: () => { throw new Error('log unavailable'); } });
	await badLogger.run({ bootPayload: {} });
});

test('refuses malformed, oversized, invalid UTF-8 and unfinished frames', async () => {
	const cases = [
		[encoder.encode('\x1eATOMS:-1\n'), /length/],
		[encoder.encode('\x1eATOMS:01\n!'), /length/],
		[encoder.encode('\x1eATOMS:1025\n'), /byte limit/],
		[encoder.encode('\x1eATOMS:99999999999999'), /oversized/],
		[encoder.encode('\x1eATOMS:1\n?'), /door/],
		[new Uint8Array([...encoder.encode('\x1eATOMS:2\n!'), 255]), /UTF-8/],
		[encoder.encode('\x1eATOMS:2\n!'), /incomplete/],
	];
	for (const [bytes, expected] of cases) {
		const host = fixture(async ({ input, output }) => {
			await line(input);
			output.blockingWriteAndFlush(bytes);
		});
		await assert.rejects(host.run({ bootPayload: {} }), expected);
	}
});

test('a rejected deferred reply wakes the pending guest read with its failure', async () => {
	const host = fixture(async ({ input, output }) => {
		await line(input);
		output.blockingWriteAndFlush(frame('~{}'));
		await input.blockingRead(1n);
	}, {
		onPark: (_message, reply) => queueMicrotask(() => reply('x'.repeat(1025))),
	});
	await assert.rejects(host.run({ bootPayload: {} }), /reply exceeds/);
});

test('closing a parked instance wakes reads and ignores stale or duplicate replies', async () => {
	let reply;
	let parked;
	const ready = new Promise(resolve => { parked = resolve; });
	const host = fixture(async ({ input, output }) => {
		await line(input);
		output.blockingWriteAndFlush(frame('~{}'));
		await input.blockingRead(1n);
	}, { onPark: (_message, callback) => { reply = callback; parked(); } });
	const run = host.run({ bootPayload: {} });
	await ready;
	host.exit();
	reply('stale');
	reply('stale again');
	await assert.rejects(run, error => error?.tag === 'closed');
	await assert.rejects(host.run({ bootPayload: {} }), /only be run once/);
});

test('only accepts precompiled modules and refuses missing boot input', async () => {
	const missing = fixture(() => {}, {
		instantiate: async getModule => { getModule('missing.wasm'); },
	});
	await assert.rejects(missing.run({ bootPayload: {} }), /missing precompiled core module/);
	const bytes = fixture(() => {}, {
		coreModules: { 'program.wasm': new Uint8Array() },
		instantiate: async getModule => { getModule('program.wasm'); },
	});
	await assert.rejects(bytes.run({ bootPayload: {} }), /precompiled core module/);
	await assert.rejects(fixture(() => {}).run({ code: '<?php ?>' }), /boot payload/);
});

test('guest filesystem paths cannot traverse or overwrite a directory', () => {
	const host = fixture(() => {});
	host.mkdir('/app');
	host.writeFile('/app/__proto__/file.php', 'source');
	assert.equal(host.fileExists('/app/__proto__/file.php'), true);
	assert.equal(host.fileExists('/app/missing.php'), false);
	assert.throws(() => host.writeFile('/app/../escape', 'source'), /normalized/);
	assert.throws(() => host.writeFile('/app', 'source'), /directory/);
	assert.throws(() => host.mkdir('/app/__proto__/file.php'), /directory/);
});
