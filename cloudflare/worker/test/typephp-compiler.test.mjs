import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { after, test } from 'node:test';
import { compileTypePhp } from '../scripts/lib/typephp-compiler.mjs';
import { fakeTypePhpTools } from './typephp-fake-tools.mjs';

const scratch = fs.mkdtempSync(path.join(os.tmpdir(), 'atoms-typephp-compiler-test-'));
const { phpxHome, calls, env } = fakeTypePhpTools(scratch);
const hashes = new Set();
const sources = { '/app/main.php': '<?php function main(): void {}', '/app/support.php': '<?php function support(): int { return 1; }' };

function build(name, options = {}) {
	const result = compileTypePhp({ sources, outputDir: path.join(scratch, name), phpxHome, env, ...options });
	hashes.add(result.sourceHash);
	return result;
}

after(() => {
	fs.rmSync(scratch, { recursive: true, force: true });
	for (const hash of hashes) fs.rmSync(path.join('/tmp/atoms-typephp', hash), { recursive: true, force: true });
});

test('canonical staging produces identical artifacts regardless of output location or input order', () => {
	const first = build('one');
	const second = build('two', { sources: Object.fromEntries(Object.entries(sources).reverse()) });
	assert.equal(first.sourceHash, second.sourceHash);
	assert.deepEqual(fs.readFileSync(first.componentPath), fs.readFileSync(second.componentPath));
	assert.equal(first.componentHash, second.componentHash);
	assert.deepEqual(first.modules, ['js/program.conditional-host-import-0.wasm', 'js/program.core.wasm']);
	for (const file of [first.entryPath, ...first.modulePaths]) assert.ok(fs.existsSync(file));
	const invocations = fs.readFileSync(calls, 'utf8').trim().split('\n').map(JSON.parse);
	assert.ok(invocations.every((call) => call.args.length === 1 && call.epoch === '0'));
});

test('a compiler or transpiler failure preserves the previous complete bundle and releases locks', () => {
	const initial = build('preserved');
	const before = fs.readFileSync(path.join(scratch, 'preserved/typephp-build.json'));
	for (const [flag, message] of [['FAKE_COMPILE_FAILURE', /compiler diagnostic/], ['FAKE_JCO_FAILURE', /transpiler diagnostic/]]) {
		assert.throws(() => build('preserved', { env: { ...env, [flag]: '1' } }), message);
		assert.deepEqual(fs.readFileSync(path.join(scratch, 'preserved/typephp-build.json')), before);
		assert.equal(fs.existsSync(`/tmp/atoms-typephp/${initial.sourceHash}.lock`), false);
	}
	assert.equal(build('preserved').componentHash, initial.componentHash);
});

test('rejects a core module passed off as a component and incomplete SDK symbols', () => {
	assert.throws(() => build('bad-component', { env: { ...env, FAKE_BAD_HEADER: '1' } }), /invalid WebAssembly component header/);
	assert.equal(fs.existsSync(path.join(scratch, 'bad-component')), false);
	const before = fs.readFileSync(calls, 'utf8');
	assert.throws(() => build('bad-sdk', { env: { ...env, FAKE_MISSING_SYMBOL: '1' } }), /Incomplete TypePHP WASI SDK.*typephp_runtime_start/);
	assert.equal(fs.readFileSync(calls, 'utf8'), before);
});

test('rejects traversal, symlink outputs, and directories not owned by this bundler', () => {
	for (const name of ['/../escape.php', '/app/../escape.php', '/app//file.php', 'relative.php', '/app\\file.php']) {
		assert.throws(() => build('bad-path', { sources: { [name]: '<?php' } }), /Invalid TypePHP source path/);
	}
	fs.mkdirSync(path.join(scratch, 'unrelated'));
	fs.writeFileSync(path.join(scratch, 'unrelated/keep'), 'user file');
	assert.throws(() => build('unrelated'), /Refusing to replace/);
	fs.symlinkSync(path.join(scratch, 'unrelated'), path.join(scratch, 'symlink'));
	assert.throws(() => build('symlink'), /plain directory/);
	assert.equal(fs.readFileSync(path.join(scratch, 'unrelated/keep'), 'utf8'), 'user file');
});

test('refuses concurrent builds and substituted immutable source files', () => {
	const initial = build('locked');
	const lock = `/tmp/atoms-typephp/${initial.sourceHash}.lock`;
	fs.writeFileSync(lock, '', { flag: 'wx', mode: 0o600 });
	try { assert.throws(() => build('concurrent'), /already using/); } finally { fs.unlinkSync(lock); }
	const source = `/tmp/atoms-typephp/${initial.sourceHash}/sources/app/main.php`;
	fs.unlinkSync(source);
	fs.symlinkSync(path.join(scratch, 'unrelated/keep'), source);
	assert.throws(() => build('substituted'), /regular file/);
	fs.unlinkSync(source);
	fs.writeFileSync(source, sources['/app/main.php'], { mode: 0o600 });
});
