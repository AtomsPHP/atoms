#!/usr/bin/env node

import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { spawnSync } from 'node:child_process';
import { existsSync, mkdtempSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { tmpdir } from 'node:os';
import { fileURLToPath } from 'node:url';
import { gzipSync } from 'node:zlib';
import { stageRuntimePackage } from '../scripts/pack-runtime.mjs';

const workerRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const release = JSON.parse(readFileSync(resolve(workerRoot, '../../release/manifest.json'), 'utf8'));

function run(command, args, options = {}) {
	const { expectFailure = false, ...spawnOptions } = options;
	const result = spawnSync(command, args, { encoding: 'utf8', ...spawnOptions });
	if (result.status !== 0 && !expectFailure) {
		throw new Error(
			`${command} ${args.join(' ')} failed (${result.status})\n${result.stdout}\n${result.stderr}`,
		);
	}
	return result;
}

const temporaryRoot = mkdtempSync(join(tmpdir(), 'atoms-runtime-package-test-'));
try {
	const npmEnvironment = { ...process.env, npm_config_cache: join(temporaryRoot, 'npm-cache') };
	const stage = join(temporaryRoot, 'package');
	const packed = join(temporaryRoot, 'packed');
	const target = join(temporaryRoot, 'worker');
	mkdirSync(packed);

	const { packageManifest, templateManifest } = stageRuntimePackage(stage);
	assert.equal(packageManifest.name, '@atomsphp/runtime-cloudflare');
	assert.equal(packageManifest.version, release.runtime.version);
	assert.equal(packageManifest.license, 'MIT');
	assert.equal(templateManifest.license, 'MIT');
	assert.equal(templateManifest.devDependencies.wrangler, release.runtime.wrangler);
	assert.ok(existsSync(join(stage, 'template', 'package-lock.json')));
	assert.ok(!existsSync(join(stage, 'template', 'src', 'bundle.generated.js')));

	// What customers scaffold is the scaffold config, not the conformance
	// harness's: debug endpoints must default off (the flag is absent, so the
	// worker's own `bool(env, 'ATOMS_DEBUG_ENDPOINTS', false)` default rules),
	// and the harness's worker name must not leak into customer projects.
	const templateWrangler = readFileSync(join(stage, 'template', 'wrangler.jsonc'), 'utf8');
	assert.ok(!existsSync(join(stage, 'template', 'wrangler.scaffold.jsonc')));
	assert.ok(
		!/"ATOMS_DEBUG_ENDPOINTS"\s*:/.test(templateWrangler),
		'the scaffolded wrangler.jsonc must not set ATOMS_DEBUG_ENDPOINTS; it is enabled via atoms.json debug_endpoints',
	);
	assert.ok(
		!templateWrangler.includes('"name": "atoms-mvp-conformance"'),
		'the scaffolded wrangler.jsonc must not carry the conformance harness worker name',
	);

	const pack = run('npm', ['pack', stage, '--pack-destination', packed, '--json'], { env: npmEnvironment });
	const packResult = JSON.parse(pack.stdout);
	assert.equal(packResult.length, 1);
	const tarball = join(packed, packResult[0].filename);

	const listing = run('tar', ['-tzf', tarball]).stdout.split(/\r?\n/).filter(Boolean);
	const forbidden = [
		'/.php-wasm/',
		'/node_modules/',
		'/fixtures/',
		'/test/',
		'/bundle.generated.js',
		'/pdo-matrix.json',
		'/remote.json',
	];
	for (const fragment of forbidden) {
		assert.ok(!listing.some((file) => file.includes(fragment)), `tarball contains forbidden ${fragment}`);
	}
	for (const required of [
		'package/template/package-lock.json',
		'package/template/release/supported-core',
		'package/template/scripts/prepare-runtime.mjs',
		'package/template/scripts/bundle-from-cli.mjs',
		'package/template/scripts/lib/bundle-module.mjs',
		'package/template/src/index.js',
		'package/template/wrangler.jsonc',
		'package/LICENSE',
		'package/THIRD_PARTY_NOTICES.md',
		'package/corresponding-source/README.md',
	]) {
		assert.ok(listing.includes(required), `tarball is missing ${required}`);
	}
	assert.ok(!listing.includes('package/LICENSE-MIT'));

	mkdirSync(target);
	run('npm', [
		'exec',
		'--yes',
		`--package=${tarball}`,
		'--',
		'atoms-runtime-cloudflare',
		'init',
		target,
	], { env: npmEnvironment });
	assert.ok(existsSync(join(target, 'package.json')));
	assert.ok(existsSync(join(target, 'package-lock.json')));
	assert.ok(existsSync(join(target, '.gitignore')));
	assert.ok(existsSync(join(target, 'LICENSE')));
	assert.ok(existsSync(join(target, 'THIRD_PARTY_NOTICES.md')));
	assert.ok(!existsSync(join(target, 'src', 'bundle.generated.js')));

	const emptyTar = Buffer.alloc(1024);
	const bundle = join(temporaryRoot, 'bundle.tar.gz');
	writeFileSync(bundle, gzipSync(emptyTar, { mtime: 0 }));
	const manifestPath = join(temporaryRoot, 'manifest.json');
	const workerBundle = join(target, 'src', 'bundle.generated.js');
	const baseManifest = {
		schema: 1,
		project: 'runtime-package-test',
		atoms: [],
		toolchain: { core_version: release.version, php: '8.3', extensions: [], scoper_prefix: 'AtomsScoped' },
		content_hash: createHash('sha256').update(emptyTar).digest('hex'),
	};
	writeFileSync(manifestPath, `${JSON.stringify(baseManifest)}\n`);
	run('node', [join(target, 'scripts', 'bundle-from-cli.mjs'), bundle, manifestPath, workerBundle]);
	assert.ok(existsSync(workerBundle), 'a release-matched bundle should stage');

	// The scaffolded worker must actually build. Asserting that a list of paths
	// exists cannot catch a module that is imported by shipped code but was
	// never packaged — that is how a package whose every `wrangler dev` died at
	// "Could not resolve ./derive.js" shipped green. Bundle the real scaffold
	// with the same tool wrangler uses and fail on any unresolved import.
	const esbuild = join(workerRoot, 'node_modules', '.bin', 'esbuild');
	assert.ok(existsSync(esbuild), 'the resolution gate needs esbuild: run `npm ci` in cloudflare/worker');
	// `npm ci` runs prepare-runtime.mjs, which stages the php-wasm artifact from
	// npm into .php-wasm/. Stub it: this gate measures the package's own module
	// graph, and the staged runtime is neither packaged nor redistributable.
	mkdirSync(join(target, '.php-wasm', '8_3_32'), { recursive: true });
	writeFileSync(join(target, '.php-wasm', 'php_8_3.js'), 'export const dependencyFilename = null;\n');
	writeFileSync(join(target, '.php-wasm', '8_3_32', 'php_8_3.wasm'), '');
	const resolution = run(esbuild, [
		join(target, 'src', 'index.js'),
		'--bundle',
		'--format=esm',
		'--platform=neutral',
		'--packages=external',
		'--loader:.wasm=binary',
		`--outfile=${join(temporaryRoot, 'resolution-check.js')}`,
	], { expectFailure: true });
	assert.equal(
		resolution.status,
		0,
		`the scaffolded worker's module graph does not resolve — a module imported by shipped `
			+ `code is missing from the package:\n${resolution.stderr}`,
	);

	rmSync(workerBundle);
	writeFileSync(
		manifestPath,
		`${JSON.stringify({ ...baseManifest, toolchain: { ...baseManifest.toolchain, core_version: '999.0.0' } })}\n`,
	);
	const incompatible = run(
		'node',
		[join(target, 'scripts', 'bundle-from-cli.mjs'), bundle, manifestPath, workerBundle],
		{ expectFailure: true },
	);
	assert.notEqual(incompatible.status, 0);
	assert.match(incompatible.stderr, /ATOMS-E043/);
	assert.match(incompatible.stderr, /999\.0\.0/);
	assert.ok(incompatible.stderr.includes(release.core.supported));
	assert.ok(!existsSync(workerBundle), 'an incompatible bundle must not be emitted');

	const secondRun = run('npm', [
		'exec',
		'--yes',
		`--package=${tarball}`,
		'--',
		'atoms-runtime-cloudflare',
		'init',
		target,
	], { expectFailure: true, env: npmEnvironment });
	assert.notEqual(secondRun.status, 0);
	assert.match(secondRun.stderr, /refusing to overwrite/);

	const nonempty = join(temporaryRoot, 'nonempty');
	mkdirSync(nonempty);
	writeFileSync(join(nonempty, 'owned-by-user.txt'), 'keep\n');
	const refuseUserFile = run('npm', [
		'exec',
		'--yes',
		`--package=${tarball}`,
		'--',
		'atoms-runtime-cloudflare',
		'init',
		nonempty,
	], { expectFailure: true, env: npmEnvironment });
	assert.notEqual(refuseUserFile.status, 0);
	assert.equal(readFileSync(join(nonempty, 'owned-by-user.txt'), 'utf8'), 'keep\n');

	console.log('runtime package allowlist and local-tarball scaffold: ok');
} finally {
	rmSync(temporaryRoot, { recursive: true, force: true });
}
