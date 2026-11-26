// Runs dedicated local Wrangler instances so the shipped entry and other
// conformance postures never acquire a transport override.
import assert from 'node:assert/strict';
import { createHmac, hkdfSync, randomBytes } from 'node:crypto';
import { once } from 'node:events';
import { createServer } from 'node:http';
import { setTimeout as delay } from 'node:timers/promises';
import { unstable_dev } from 'wrangler';

const origin = 'https://api.example.com';
const secret = randomBytes(32);
const byteLimit = 512;
const hits = [];
let held;
let oversizedHook;
let closedStalls = 0;
const recorder = createServer(async (req, res) => {
	const hit = { path: req.url, method: req.method, headers: req.headers };
	hits.push(hit); // Count arrival even if a caller disconnects before sending its last byte.
	const chunks = [];
	for await (const chunk of req) chunks.push(chunk);
	const body = Buffer.concat(chunks);
	hit.body = body;
	if (req.url === '/callback') {
		const step = JSON.parse(body).args[0];
		if (step === 'slowApp') return;
		if (step === 'delayedApp') await delay(320);
		return res.end('{"result":"app-ok"}');
	}
	if (req.url === '/delayedHttp') await delay(320);
	if (req.url === '/drop') {
		res.writeHead(200, { 'content-length': '20' });
		res.write('partial');
		await delay(30);
		return res.destroy();
	}
	if (req.url === '/stall') {
		res.write('first');
		res.on('close', () => closedStalls++);
		return;
	}
	if (req.url === '/hold') {
		held = res;
		return;
	}
	if (req.url === '/echo') {
		res.writeHead(409, { 'set-cookie': ['a=1; Path=/', 'b=2; Path=/'], 'x-result': ['one', 'two'] });
		return res.end(body);
	}
	if (req.url === '/redirect') {
		res.writeHead(307, { location: origin + '/target' });
		return res.end();
	}
	if (req.url.startsWith('/sized/') || req.url === oversizedHook) {
		res.writeHead(200, { 'transfer-encoding': 'chunked' });
		const length = req.url === oversizedHook ? byteLimit : Number(req.url.slice('/sized/'.length));
		res.write(Buffer.alloc(Math.floor(length / 2)));
		return res.end(Buffer.alloc(Math.ceil(length / 2)));
	}
	res.writeHead(req.url === '/unavailable' ? 503 : 204);
	res.end();
});
recorder.listen(0, '127.0.0.1');
await once(recorder, 'listening');
const port = String(recorder.address().port);
async function until(check) {
	for (let i = 0; i < 250; i++) {
		if (check()) return;
		await delay(20);
	}
	assert.fail('Timed out waiting for recorder');
}

async function withWorker(overrides, test) {
	const worker = await unstable_dev('test/http-worker.js', {
		config: 'wrangler.jsonc', local: true, persist: false, port: 0, inspectorPort: 0, logLevel: 'error',
		vars: {
			RECORDER_PORT: port, ATOMS_BEARER_AUTH: 'disabled',
			ATOMS_SHARED_SECRET: secret.toString('base64'), ATOMS_CALLBACK_URL: 'http://127.0.0.1:' + port + '/callback',
			ATOMS_TURN_DEADLINE_MS: '3000', ATOMS_HTTP_TIMEOUT_MS: '600',
			ATOMS_HTTP_MAX_REQUEST_BYTES: String(byteLimit), ATOMS_HTTP_MAX_RESPONSE_BYTES: String(byteLimit),
			ATOMS_HTTP_MAX_REQUESTS_PER_TURN: '2', ...overrides,
		},
		experimental: { watch: false, disableExperimentalWarning: true, disableDevRegistry: true },
	});
	const base = 'http://127.0.0.1:' + worker.port;
	async function invoke(method, args, id = 'probe') {
		const res = await fetch(base + '/invoke/HttpProbe/' + id + '/' + method, {
			method: 'POST', headers: { 'content-type': 'application/json', authorization: 'Bearer inbound-only', cookie: 'inbound=1' },
			body: JSON.stringify({ args }),
		});
		const frame = await res.json();
		if (frame.error) throw new Error(frame.error.code + ' ' + frame.error.message);
		return frame.result;
	}
	try {
		await test(invoke, base);
	} finally {
		await worker.stop();
	}
}

try {
	await withWorker({}, async (invoke, base) => {
		const exchange = (method, url, headers = {}, body = null) => invoke('exchange', [method, url, headers, body]);
		const binary = Buffer.from([0, 255, 128, 42]);
		const response = await exchange('POST', origin + '/echo', { Authorization: 'Bearer external', 'x-input': ['a', 'b'] }, binary.toString('base64'));
		assert.equal(response[0], 409);
		assert.deepEqual(response[1]['set-cookie'], ['a=1; Path=/', 'b=2; Path=/']);
		assert.deepEqual(response[1]['x-result'], ['one, two']);
		assert.equal(response[2], binary.toString('base64'));
		assert.equal(hits.length, 1);
		assert.deepEqual(hits[0].body, binary);
		assert.equal(hits[0].headers.authorization, 'Bearer external');
		assert.equal(hits[0].headers['x-input'], 'a, b');
		assert.equal(hits[0].headers.cookie, undefined);
		assert.ok(!Object.keys(hits[0].headers).some(k => k.startsWith('x-atoms-')));
		const redirect = await exchange('GET', origin + '/redirect');
		assert.equal(redirect[0], 307);
		assert.deepEqual(redirect[1].location, [origin + '/target']);
		assert.equal(hits.length, 2);
		assert.equal(hits[1].headers.authorization, undefined);
		assert.equal(hits[1].headers.cookie, undefined);

		for (const destination of ['https://else.example.com', origin + ':8443', 'https://localhost',
			'https://db.internal', 'https://127.0.0.1', 'https://[::1]:8443']) {
			const url = destination + '/status?batch=2';
			const before = hits.length;
			assert.equal((await exchange('GET', url))[0], 204);
			assert.equal(hits.length, before + 1);
			assert.equal(hits.at(-1).headers['x-recorder-url'], url);
		}

		const before = hits.length;
		for (const url of ['http://api.example.com', '/relative', 'https://user@api.example.com',
			'https://@api.example.com', origin + '/private?secret=value#']) {
			await assert.rejects(exchange('GET', url), /ATOMS-E118/);
		}
		for (const name of ['CF-Test', 'X-Forwarded-Host', 'X-Atoms-Signature', 'Host', 'Content-Length', 'Connection', 'Proxy-Authorization']) {
			await assert.rejects(exchange('POST', origin, { [name]: 'value' }), /ATOMS-E117/);
		}
		await assert.rejects(exchange('HEAD', origin, {}, ''), /ATOMS-E118/);
		await assert.rejects(exchange('POST', origin, { 'x-bad': 'v\nx' }), /ATOMS-E118/);
		for (const kind of ['helper', 'pdo']) await assert.rejects(invoke('transaction', [kind]), /ATOMS-E119/);
		await assert.rejects(invoke('transaction', ['sql']), /transactionSync|transaction.*API/i);
		await assert.rejects(invoke('http', []), /method_not_found/);
		assert.equal(hits.length, before, 'local refusals must leave independent upstream count unchanged');

		const url = origin + '/accepted';
		const headers = { 'x-limit': 'aa' };
		const space = byteLimit - Buffer.byteLength('PUT' + url + 'x-limitaa');
		const accepted = await exchange('PUT', url, headers, Buffer.alloc(space).toString('base64'));
		assert.equal(accepted[0], 204);
		assert.equal(accepted[2], '');
		await assert.rejects(exchange('PUT', url, headers, Buffer.alloc(space + 1).toString('base64')), /ATOMS-E121/);
		assert.equal(hits.length, before + 1);
		const [, fields] = await exchange('GET', origin + '/sized/0');
		const fieldBytes = Object.entries(fields).reduce((sum, [key, values]) =>
			sum + values.reduce((total, value) => total + Buffer.byteLength(key + value), 0), 3);
		const exact = byteLimit - fieldBytes;
		assert.equal(Buffer.from((await exchange('GET', origin + '/sized/' + exact))[2], 'base64').length, exact);
		await assert.rejects(exchange('GET', origin + '/sized/' + (exact + 1)), /ATOMS-E121/);

		let count = hits.length;
		assert.deepEqual(await invoke('requests', [['stall', 'app', 'ok']]), ['ATOMS-E120', 'app-ok', 204]);
		assert.equal(hits.length, count + 3);
		await until(() => closedStalls > 0);
		count = hits.length;
		assert.deepEqual(await invoke('requests', [['ok', 'unavailable', 'no-slot']]), [204, 503, 'ATOMS-E122']);
		assert.equal(hits.length, count + 2);
		assert.deepEqual(await invoke('requests', [['fresh']]), [204]);
		count = hits.length;
		assert.deepEqual(await invoke('requests', [['drop', 'ok', 'no-slot']]), ['ATOMS-E123', 204, 'ATOMS-E122']);
		assert.equal(hits.length, count + 2);

		const first = invoke('requests', [['hold']]);
		await until(() => held);
		const second = invoke('requests', [['second']]);
		await delay(50);
		assert.equal(hits.at(-1).path, '/hold');
		held.writeHead(204).end();
		assert.deepEqual(await Promise.all([first, second]), [[204], [204]]);
		assert.equal(hits.at(-1).path, '/second');

		await assert.rejects(invoke('writeThenFetch', []), /ATOMS-E120/);
		assert.equal(await invoke('writes', []), 1);

		const id = 'lifecycle';
		assert.deepEqual(await invoke('requests', [['one', 'two']], id), [204, 204]);
		assert.deepEqual(hits.slice(-3).map(h => h.path), ['/activate', '/one', '/two']);
		await invoke('arm', [], id);
		await until(() => hits.some(h => h.path === '/timer'));
		const key = hkdfSync('sha256', secret, '', 'atoms/ws-ticket/v1', 32);
		const payload = Buffer.from(JSON.stringify({ t: 'HttpProbe', i: id, exp: Date.now() + 60000, jti: randomBytes(16).toString('hex'), claims: {} })).toString('base64url');
		const signature = createHmac('sha256', key).update('v1\n' + payload).digest('base64url');
		const socket = new WebSocket(base.replace('http:', 'ws:') + '/ws/HttpProbe/' + id + '?ticket=v1.' + payload + '.' + signature);
		await once(socket, 'open');
		const reply = once(socket, 'message');
		socket.send('fetch');
		assert.equal((await reply)[0].data, '204');
		assert.equal(hits.at(-1).path, '/socket');
		const closed = once(socket, 'close');
		assert.equal(await invoke('writes', [], id), 0);

		oversizedHook = '/deactivate';
		const destroy = async name => (await fetch(base + '/atoms/HttpProbe/' + name, { method: 'DELETE' })).json();
		assert.deepEqual(await destroy(id), { destroyed: true });
		assert.equal(hits.at(-1).path, '/deactivate');
		await closed;
		count = hits.length;
		assert.deepEqual(await destroy(id), { destroyed: false });
		assert.equal(hits.length, count);
		oversizedHook = '/activate';
		await assert.rejects(invoke('writes', [], 'lifecycle-failed'));
		assert.equal(hits.at(-1).path, '/activate');
		oversizedHook = null;
		assert.equal(await invoke('writes', [], 'lifecycle-failed'), 0);
	});
	console.log('HTTP policy, byte limits, body timeout, serialization, durability and lifecycle passed');

	await withWorker({ ATOMS_TURN_DEADLINE_MS: '450', ATOMS_HTTP_TIMEOUT_MS: '3000' }, async invoke => {
		for (const steps of [['stall', 'app'], ['slowApp', 'ok']]) {
			const before = hits.length;
			assert.deepEqual(await invoke('requests', [steps]), ['ATOMS-E061', 'ATOMS-E061']);
			assert.equal(hits.length, before + 1, 'the latched second call must not reach the recorder');
			assert.deepEqual(await invoke('requests', [['app', 'ok']]), ['app-ok', 204]);
		}
		await assert.rejects(invoke('writeThenFetch', []), /turn_deadline_exceeded/);
		assert.equal(await invoke('writes', []), 1);
		for (const [steps, expected] of [
			[['delayedHttp', 'delayedApp', 'ok'], [204, 'ATOMS-E061', 'ATOMS-E061']],
			[['delayedApp', 'delayedHttp', 'app'], ['app-ok', 'ATOMS-E061', 'ATOMS-E061']],
		]) {
			const before = hits.length;
			assert.deepEqual(await invoke('requests', [steps]), expected);
			assert.equal(hits.length, before + 2, 'both calls spend the same remaining turn time');
		}
	});
	// A stale setting in an existing Wrangler file cannot restrict destinations.
	await withWorker({ ATOMS_HTTP_ALLOWLIST: [origin, origin + '/not-an-origin'] }, async invoke => {
		const before = hits.length;
		const url = 'https://another.example.org/status';
		assert.equal((await invoke('exchange', ['GET', url]))[0], 204);
		assert.equal(hits.length, before + 1);
		assert.equal(hits.at(-1).headers['x-recorder-url'], url);
	});
	console.log('Shared turn deadline and caller-selected HTTPS destinations passed');
} finally {
	recorder.closeAllConnections();
	await new Promise(resolve => recorder.close(resolve));
}
