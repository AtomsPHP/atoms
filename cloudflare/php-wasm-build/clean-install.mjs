// Serve only tarballs from the scaffold's reviewed lock over loopback. npm
// installs into a fresh cache with a PATH containing the JS/shell tools only.
import assert from 'node:assert/strict';
import { execFileSync, spawn } from 'node:child_process';
import { createReadStream, existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, statSync, symlinkSync } from 'node:fs';
import { createServer } from 'node:http';
import { tmpdir } from 'node:os';
import { basename, join, resolve } from 'node:path';

const [scaffoldArg, sourceCache] = process.argv.slice(2);
if (!scaffoldArg || !sourceCache) throw Error('Usage: node clean-install.mjs <scaffold.tgz> <seeded npm cache>');
const scaffold = resolve(scaffoldArg);
const manifest = JSON.parse(execFileSync('tar', ['-xOzf', scaffold, 'package/template/package-lock.json']));
const scratch = mkdtempSync(join(tmpdir(), 'atoms-npm-install-'));
const requests = new Set();
const unexpected = [];
const files = new Map();
const owned = new Set();
for (const [name, entry] of Object.entries(manifest.packages)) {
	if (!entry.resolved) continue;
	const path = decodeURIComponent(new URL(entry.resolved).pathname);
	assert.doesNotMatch(name + path, /@php-wasm\/|@wp-playground\/|wordpress-playground/i);
	const [algorithm, base64] = entry.integrity.split('-');
	const hash = Buffer.from(base64, 'base64').toString('hex');
	files.set(path, join(sourceCache, '_cacache/content-v2', algorithm, hash.slice(0, 2), hash.slice(2, 4), hash.slice(4)));
	if (name.startsWith('node_modules/@atomsphp/php-wasm-')) owned.add(path);
}
assert.equal(owned.size, 3);
const server = createServer((req, res) => {
	const path = decodeURIComponent(new URL(req.url, 'http://localhost').pathname);
	const file = files.get(path);
	if (req.method !== 'GET' || !file || !existsSync(file)) {
		unexpected.push(path);
		res.writeHead(404).end();
		return;
	}
	requests.add(path);
	res.writeHead(200, { 'content-type': 'application/octet-stream', 'content-length': statSync(file).size });
	createReadStream(file).pipe(res);
});
try {
	await new Promise((done, fail) => server.once('error', fail).listen(0, '127.0.0.1', done));
	const bin = join(scratch, 'bin');
	mkdirSync(bin);
	for (const command of ['node', 'npm', 'sh', 'bash']) {
		const src = command === 'node' ? process.execPath : execFileSync('which', [command], { encoding: 'utf8' }).trim();
		symlinkSync(src, join(bin, command));
	}
	const cache = join(scratch, 'cache');
	const env = {
		...process.env, PATH: bin, npm_config_cache: cache, npm_config_audit: 'false', npm_config_fund: 'false',
		npm_config_registry: `http://127.0.0.1:${server.address().port}/`, npm_config_replace_registry_host: 'always',
		npm_config_offline: 'false', npm_config_fetch_retries: '0', npm_config_update_notifier: 'false',
	};
	const app = join(scratch, 'worker');
	const npm = (cwd, args) => new Promise((done, fail) => {
		const child = spawn(join(bin, 'npm'), args, { cwd, env, stdio: 'inherit' });
		child.once('error', fail);
		child.once('exit', status => status === 0 ? done() : fail(Error(`npm ${args[0]} failed (${status}); missing requests: ${unexpected.join(', ')}`)));
	});
	assert.equal(existsSync(cache), false);
	await npm(scratch, ['exec', '--yes', '--package=' + scaffold, '--', 'atoms-runtime-cloudflare', 'init', app]);
	await npm(app, ['ci']);
	for (const path of owned) assert.ok(requests.has(path), `npm did not fetch ${basename(path)}`);
	await npm(scratch, ['exec', '--yes', '--package=' + scaffold, '--', 'atoms-runtime-cloudflare', 'upgrade', app]);
	await npm(app, ['ci']);
	assert.deepEqual(unexpected, []);
	const pins = JSON.parse(readFileSync(join(app, 'release/interpreters.json'), 'utf8'));
	for (const pin of Object.values(pins)) assert.ok(existsSync(join(app, '.php-wasm', pin.glue.path)));
	console.log(`Installed all three interpreter tarballs from an empty cache (${requests.size} distinct tarballs).`);
} finally {
	server.closeAllConnections();
	await new Promise(resolve => server.close(resolve));
	rmSync(scratch, { recursive: true, force: true });
}
