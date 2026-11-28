// Exercise the customer scaffold's npm lifecycle hook on a small installed set.
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { cpSync, existsSync, mkdirSync, mkdtempSync, readFileSync, renameSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import test from 'node:test';

const hash = bytes => createHash('sha256').update(bytes).digest('hex');

test('staging verifies the entire install before replacing any per-minor entry', t => {
	const root = mkdtempSync(join(tmpdir(), 'atoms-prepare-'));
	t.after(() => rmSync(root, { recursive: true, force: true }));
	for (const dir of ['scripts/lib', 'release']) mkdirSync(join(root, dir), { recursive: true });
	for (const file of ['scripts/prepare-runtime.mjs', 'scripts/lib/php-runtimes.mjs']) {
		cpSync(new URL('../' + file, import.meta.url), join(root, file));
	}
	const pins = {};
	const pinsPath = join(root, 'release/interpreters.json');
	const out = join(root, '.php-wasm');
	const modules = join(root, 'node_modules');
	const pkg = pin => join(modules, pin.package);
	function install(series, revision) {
		const name = '@atomsphp/php-wasm-' + series.replace('.', '-');
		const version = series + '.0-atoms.' + revision;
		const pin = { package: name, version };
		mkdirSync(pkg(pin), { recursive: true });
		writeFileSync(join(pkg(pin), 'package.json'), JSON.stringify({ name, version }));
		for (const [key, ext] of [['wasm', '.wasm'], ['glue', '.js']]) {
			const path = 'php_' + series.replace('.', '_') + ext;
			const bytes = Buffer.from(name + '@' + version + path);
			pin[key] = { path, bytes: bytes.length, sha256: hash(bytes) };
			writeFileSync(join(pkg(pin), path), bytes);
		}
		pins[series] = pin;
		writeFileSync(pinsPath, JSON.stringify(pins));
	}

	const run = (lockOnly = false) => spawnSync(process.execPath, [join(root, 'scripts/prepare-runtime.mjs'), '--if-installed'], {
		env: { ...process.env, npm_config_package_lock_only: String(lockOnly) }, encoding: 'utf8',
	});
	for (const series of ['8.3', '8.4', '8.5']) install(series, 1);
	const initial = run();
	assert.equal(initial.status, 0, initial.stderr);
	const original = structuredClone(pins);
	function assertStage(expected) {
		for (const pin of Object.values(expected)) {
			const pair = pin.wasm.sha256 + '-' + pin.glue.sha256;
			assert.equal(readFileSync(join(out, pin.glue.path), 'utf8'), [
				`export { default as factory } from "./${pair}/${pin.glue.path}";`,
				`export { default as wasm } from "./${pair}/${pin.wasm.path}";`,
				'',
			].join('\n'));
			for (const file of [pin.wasm, pin.glue]) assert.equal(hash(readFileSync(join(out, pair, file.path))), file.sha256);
		}
	}
	assertStage(original);
	install('8.3', 2); // A valid upgrade for the first package is now pending.
	const last = pins['8.5'];
	for (const key of ['wasm', 'glue']) {
		const file = join(pkg(last), last[key].path);
		const good = readFileSync(file);
		rmSync(file);
		assert.notEqual(run().status, 0);
		assertStage(original);
		for (const bad of [Buffer.alloc(0), Buffer.alloc(good.length), readFileSync(join(pkg(pins['8.4']), pins['8.4'][key].path))]) {
			writeFileSync(file, bad);
			assert.match(run().stderr, /pinned artifact mismatch/);
			assertStage(original);
		}
		writeFileSync(file, good);
	}
	for (const field of ['name', 'version']) {
		const path = join(pkg(last), 'package.json');
		const good = readFileSync(path);
		writeFileSync(path, JSON.stringify({ ...JSON.parse(good), [field]: 'wrong' }));
		assert.match(run().stderr, /expected @atomsphp/);
		assertStage(original);
		writeFileSync(path, good);
	}
	renameSync(pkg(last), join(root, 'saved'));
	assert.notEqual(run(true).status, 0, 'a partial install must fail even for a lockfile-only operation');
	assertStage(original);
	renameSync(join(root, 'saved'), pkg(last));
	const upgraded = run();
	assert.equal(upgraded.status, 0, upgraded.stderr);
	assertStage(pins);
	const old = original['8.3'];
	assert.equal(existsSync(join(out, old.wasm.sha256 + '-' + old.glue.sha256)), false);

	rmSync(modules, { recursive: true });
	assert.notEqual(run().status, 0, 'an ordinary install must have all interpreter packages');
	assert.equal(run(true).status, 0);
	assertStage(pins);
});
