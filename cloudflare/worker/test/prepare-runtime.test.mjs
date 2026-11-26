// Exercise the scaffold's npm prepare hook with an installed package and a
// separately pinned pair. Each bad install must fail before the import changes.
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { cpSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import test from 'node:test';

test('prepare verifies both package files before selecting the runtime', (t) => {
	const root = mkdtempSync(join(tmpdir(), 'atoms-prepare-'));
	t.after(() => rmSync(root, { recursive: true, force: true }));
	const name = '@atomsphp/php-wasm-8-3';
	const version = '8.3.32-test.1';
	const pkg = join(root, 'node_modules', name);
	const script = join(root, 'scripts/prepare-runtime.mjs');
	for (const dir of [pkg, join(root, 'release'), join(root, 'scripts')]) mkdirSync(dir, { recursive: true });
	cpSync(new URL('../scripts/prepare-runtime.mjs', import.meta.url), script);
	const wasm = Buffer.from([0, 97, 115, 109, 1, 0, 0, 0]);
	const glue = Buffer.from("export { default as dependencyFilename } from './php_8_3.wasm';\n");
	const file = (path, bytes) => ({ path, bytes: bytes.length, sha256: createHash('sha256').update(bytes).digest('hex') });
	const pin = { package: name, version, wasm: file('php_8_3.wasm', wasm), glue: file('php_8_3.js', glue) };
	writeFileSync(join(root, 'release/interpreter.json'), JSON.stringify(pin));
	writeFileSync(join(pkg, 'package.json'), JSON.stringify({ name, version }));
	writeFileSync(join(pkg, pin.wasm.path), wasm);
	writeFileSync(join(pkg, pin.glue.path), glue);
	const run = () => spawnSync(process.execPath, [script, '--if-installed'], { encoding: 'utf8' });
	const initial = run();
	assert.equal(initial.status, 0, initial.stderr);
	const dir = join(root, '.php-wasm', pin.wasm.sha256 + '-' + pin.glue.sha256);
	const unchanged = () => {
		assert.deepEqual(readFileSync(join(dir, pin.wasm.path)), wasm);
		assert.deepEqual(readFileSync(join(dir, pin.glue.path)), glue);
		assert.match(readFileSync(join(root, '.php-wasm/runtime.js'), 'utf8'), new RegExp(pin.glue.sha256));
	};
	unchanged();

	for (const [path, bytes] of [[pin.glue.path, glue], [pin.wasm.path, wasm]]) {
		const installed = join(pkg, path);
		rmSync(installed);
		assert.notEqual(run().status, 0, 'the lifecycle hook rejected an incomplete install');
		for (const bad of [Buffer.alloc(0), Buffer.alloc(bytes.length, 42)]) {
			writeFileSync(installed, bad);
			const result = run();
			assert.notEqual(result.status, 0);
			assert.match(result.stderr, /mismatch/);
			unchanged();
		}
		writeFileSync(installed, bytes);
	}
	writeFileSync(join(pkg, 'package.json'), JSON.stringify({ name, version: '8.3.33-test.1' }));
	assert.notEqual(run().status, 0, 'a different installed version must fail');
});
