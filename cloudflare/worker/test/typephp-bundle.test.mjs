import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import crypto from 'node:crypto';
import { after, test } from 'node:test';
import { writeTypePhpBundle } from '../scripts/lib/typephp-bundle.mjs';
import { writeBundleModule } from '../scripts/lib/bundle-module.mjs';
import { fakeTypePhpTools } from './typephp-fake-tools.mjs';

const scratch = fs.mkdtempSync(path.join(os.tmpdir(), 'atoms-typephp-bundle-test-'));
const tools = fakeTypePhpTools(scratch);
const hashes = new Set();
const php = process.env.ATOMS_TYPEPHP_PHP || (process.env.PATH ?? '').split(path.delimiter)
	.map(dir => path.join(dir, 'php')).find(file => { try { fs.accessSync(file, fs.constants.X_OK); return true; } catch { return false; } });
const manifest = { abi: { php: '8.5' }, atoms: { Counter: { class: 'App\\Counter', file: '/app/Counter.php' } } };
const files = {
	'/app/Counter.php': '<?php namespace App; class Counter extends \\Atoms\\Atom {}',
	'/app/Support.php': '<?php throw new \\RuntimeException("must not execute while building");',
	'/atoms/core/src/Atom.php': fs.readFileSync(new URL('../php/atoms-core/Atom.php', import.meta.url), 'utf8'),
	'/atoms/runtime/bootstrap.php': fs.readFileSync(new URL('../php/runtime/bootstrap.php', import.meta.url), 'utf8'),
};

function build(output, flags = {}) {
	assert.ok(php, 'TypePHP source preparation requires PHP on PATH or ATOMS_TYPEPHP_PHP');
	const changes = { ...tools.env, PHPX_HOME: tools.phpxHome, ATOMS_TYPEPHP_PHP: php, ATOMS_TYPEPHP_COMPILER: path.join(tools.bin, 'tpc'), ...flags };
	const before = Object.fromEntries(Object.keys(changes).map(key => [key, process.env[key]]));
	try {
		Object.assign(process.env, changes);
		writeTypePhpBundle('integration-test', manifest, files, output);
	} finally {
		for (const [key, value] of Object.entries(before)) {
			if (value === undefined) delete process.env[key];
			else process.env[key] = value;
		}
		const artifacts = path.join(path.dirname(output), '.typephp');
		if (fs.existsSync(artifacts)) for (const hash of fs.readdirSync(artifacts)) hashes.add(hash);
	}
}

async function readBundle(output) {
	return (await import('data:text/javascript;base64,' + fs.readFileSync(output).toString('base64'))).default;
}

after(() => {
	fs.rmSync(scratch, { recursive: true, force: true });
	for (const hash of hashes) fs.rmSync(path.join('/tmp/atoms-typephp', hash), { recursive: true, force: true });
});

test('production staging switches the default selector and publishes matching provenance and static modules', async () => {
	const output = path.join(scratch, 'worker/src/bundle.generated.js');
	writeBundleModule('default-test', manifest, files, output);
	const runtime = path.join(path.dirname(output), 'runtime.generated.js');
	assert.match(fs.readFileSync(runtime, 'utf8'), /php-host-playground/);
	build(output);
	const bundle = await readBundle(output);
	assert.deepEqual(bundle.files, files, 'supporting PHP remains available to the guest');
	assert.deepEqual(manifest.abi, { php: '8.5' }, 'staging does not mutate its inputs');
	assert.equal(bundle.manifest.abi.php, '8.5');
	assert.equal(bundle.manifest.compiler.name, 'typephp');
	assert.equal(bundle.manifest.compiler.version, '0.9.5');
	assert.deepEqual(bundle.manifest.compiler.compiled_files, ['/app/Counter.php', '/atoms/core/src/Atom.php', '/atoms/runtime/bootstrap.php']);
	const artifactRoot = path.join(path.dirname(output), '.typephp', bundle.manifest.compiler.source_hash);
	const metadata = JSON.parse(fs.readFileSync(path.join(artifactRoot, 'typephp-build.json'), 'utf8'));
	const component = fs.readFileSync(path.join(artifactRoot, metadata.component));
	assert.equal(bundle.manifest.compiler.wasm_hash, crypto.createHash('sha256').update(component).digest('hex'));
	assert.equal(bundle.manifest.compiler.source_hash, metadata.sourceHash);
	const selector = fs.readFileSync(runtime, 'utf8');
	assert.match(selector, /bootTypePhp\(\{ instantiate, coreModules, \.\.\.doors \}\)/);
	assert.doesNotMatch(selector, /php-host-playground|fetch\(|WebAssembly\.compile/);
	for (const file of [metadata.entry, ...metadata.modules]) {
		assert.ok(selector.includes(`./.typephp/${metadata.sourceHash}/${file}`), `static import for ${file}`);
		assert.ok(fs.existsSync(path.join(artifactRoot, file)));
	}
	for (const module of metadata.modules) assert.ok(selector.includes(JSON.stringify(path.basename(module)) + ': core'));
	assert.ok(metadata.modules.some(file => file.includes('conditional-host-import')), 'JSPI adapter modules ship alongside the core');
	const previous = [fs.readFileSync(output), fs.readFileSync(runtime)];
	build(output);
	assert.deepEqual([fs.readFileSync(output), fs.readFileSync(runtime)], previous, 'repeating staging preserves byte-identical selectors');
});

test('compiler and transpiler failures retain the previous matching generated modules', async () => {
	const output = path.join(scratch, 'failure/src/bundle.generated.js');
	const runtime = path.join(path.dirname(output), 'runtime.generated.js');
	writeBundleModule('default-test', manifest, files, output);
	const defaultModules = [fs.readFileSync(output), fs.readFileSync(runtime)];
	assert.throws(() => build(output, { FAKE_COMPILE_FAILURE: '1' }), /compiler diagnostic/);
	assert.deepEqual([fs.readFileSync(output), fs.readFileSync(runtime)], defaultModules);
	build(output);
	const bundle = await readBundle(output);
	const complete = [fs.readFileSync(output), fs.readFileSync(runtime)];
	const artifactRoot = path.join(path.dirname(output), '.typephp', bundle.manifest.compiler.source_hash);
	const component = fs.readFileSync(path.join(artifactRoot, 'program.wasm'));
	for (const [flag, diagnostic] of [['FAKE_COMPILE_FAILURE', /compiler diagnostic/], ['FAKE_JCO_FAILURE', /transpiler diagnostic/]]) {
		assert.throws(() => build(output, { [flag]: '1' }), diagnostic);
		assert.deepEqual([fs.readFileSync(output), fs.readFileSync(runtime)], complete);
		assert.deepEqual(fs.readFileSync(path.join(artifactRoot, 'program.wasm')), component);
		assert.equal(fs.readdirSync(path.dirname(output)).some(name => name.startsWith('.typephp-stage-')), false);
	}
});
