#!/usr/bin/env node

import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { spawnSync } from 'node:child_process';
import { existsSync, lstatSync, mkdtempSync, mkdirSync, readFileSync, rmSync, symlinkSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { tmpdir } from 'node:os';
import { fileURLToPath } from 'node:url';
import { gzipSync } from 'node:zlib';
import { RUNTIME_STAMP, USER_OWNED_FILES, stageRuntimePackage } from '../scripts/pack-runtime.mjs';

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

	const { packageManifest, templateManifest, stamp } = stageRuntimePackage(stage);
	assert.equal(packageManifest.name, '@atomsphp/runtime-cloudflare');
	assert.equal(packageManifest.version, release.runtime.version);
	assert.equal(packageManifest.license, 'MIT');
	assert.equal(templateManifest.license, 'MIT');
	assert.equal(templateManifest.devDependencies.wrangler, release.runtime.wrangler);
	assert.ok(existsSync(join(stage, 'template', 'package-lock.json')));
	assert.ok(!existsSync(join(stage, 'template', 'src', 'bundle.generated.js')));

	// The stamp: the CLI reads its version (ATOMS-E108), upgrade reads its
	// ownership split. Every template file is accounted for exactly once, as
	// runtime-owned or as user-owned, and the split is the one pack-runtime
	// declares — wrangler.jsonc is the user's, nothing else is.
	const stagedStamp = JSON.parse(readFileSync(join(stage, 'template', RUNTIME_STAMP), 'utf8'));
	assert.deepEqual(stagedStamp, stamp);
	assert.equal(stamp.version, release.runtime.version);
	assert.equal(stamp.package, '@atomsphp/runtime-cloudflare');
	assert.deepEqual(stamp.user_owned, ['wrangler.jsonc']);
	assert.deepEqual(USER_OWNED_FILES, ['wrangler.jsonc']);
	for (const required of ['.gitignore', 'package.json', 'package-lock.json', 'src/index.js', 'scripts/bundle-from-cli.mjs', 'php/runtime/bootstrap.php', 'release/supported-core', 'README.md', 'LICENSE', 'THIRD_PARTY_NOTICES.md']) {
		assert.ok(stamp.runtime_owned.includes(required), `${required} must be runtime-owned in the stamp`);
	}
	assert.ok(!stamp.runtime_owned.includes('wrangler.jsonc'));
	assert.ok(!stamp.runtime_owned.includes(RUNTIME_STAMP), 'the stamp does not list itself');
	assert.deepEqual(stamp.runtime_owned, [...stamp.runtime_owned].sort(), 'the stamp is deterministic: sorted');

	// A scaffolded directory is committed, so everything the CLI generates
	// inside it must be ignored there — or every deploy dirties the tree.
	const templateGitignore = readFileSync(join(stage, 'template', 'gitignore'), 'utf8');
	for (const generated of ['/src/bundle.generated.js', '/node_modules/', '/.php-wasm/', '/.dev.vars', '/.wrangler/']) {
		assert.ok(
			templateGitignore.split(/\r?\n/).includes(generated),
			`the scaffold .gitignore must ignore ${generated}`,
		);
	}
	assert.ok(
		!templateGitignore.includes('test/.dev-secret.json'),
		'the scaffold .gitignore is not the monorepo worker\'s .gitignore',
	);

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
		!templateWrangler.includes('"name": "atoms-conformance"'),
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
	assert.ok(existsSync(join(target, 'README.md')));
	assert.ok(!existsSync(join(target, 'src', 'bundle.generated.js')));
	assert.deepEqual(JSON.parse(readFileSync(join(target, RUNTIME_STAMP), 'utf8')), stamp);
	assert.equal(readFileSync(join(target, '.gitignore'), 'utf8'), templateGitignore);
	assert.ok(
		!existsSync(join(target, 'gitignore')),
		'the template\'s dotless gitignore must be restored to .gitignore, not copied alongside it',
	);
	assert.match(
		readFileSync(join(target, 'wrangler.jsonc'), 'utf8'),
		/YOUR FILE/,
		'the scaffolded wrangler.jsonc must say it is user-owned',
	);

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

	// ---- upgrade -------------------------------------------------------------
	// The committed directory is moved to a new release by `upgrade`, which
	// writes every file the release ships except user-owned ones that exist,
	// removes runtime-owned files the previous release shipped and this one
	// does not, and leaves everything else alone. Simulated by hand-editing
	// the scaffold as an older release would have left it.
	const upgradeArgs = ['exec', '--yes', `--package=${tarball}`, '--', 'atoms-runtime-cloudflare', 'upgrade', target];
	const userWrangler = readFileSync(join(target, 'wrangler.jsonc'), 'utf8')
		.replace('"ATOMS_LOG_LEVEL": "info"', '"ATOMS_LOG_LEVEL": "debug", "USER_VAR": "kept"');
	writeFileSync(join(target, 'wrangler.jsonc'), userWrangler);
	writeFileSync(join(target, 'src', 'config.js'), '// locally edited runtime-owned file\n');
	mkdirSync(join(target, 'src', 'legacy'), { recursive: true });
	writeFileSync(join(target, 'src', 'legacy', 'old-module.js'), 'export {};\n');
	writeFileSync(join(target, 'my-notes.md'), 'not the runtime\'s, left alone\n');
	const olderStamp = { ...stamp, version: '0.0.1-older', runtime_owned: [...stamp.runtime_owned, 'src/legacy/old-module.js'] };
	writeFileSync(join(target, RUNTIME_STAMP), `${JSON.stringify(olderStamp, null, 2)}\n`);

	const upgraded = run('npm', upgradeArgs, { env: npmEnvironment });
	assert.match(upgraded.stdout, /0\.0\.1-older -> /);
	assert.match(upgraded.stdout, /removed \(no longer shipped\): src\/legacy\/old-module\.js/);
	assert.match(upgraded.stdout, /left alone \(yours\): wrangler\.jsonc/);
	assert.equal(
		readFileSync(join(target, 'src', 'config.js'), 'utf8'),
		readFileSync(join(stage, 'template', 'src', 'config.js'), 'utf8'),
		'a runtime-owned file is restored to the release\'s copy',
	);
	assert.ok(!existsSync(join(target, 'src', 'legacy')), 'a stale runtime-owned file and its emptied directory are removed');
	assert.equal(readFileSync(join(target, 'wrangler.jsonc'), 'utf8'), userWrangler, 'wrangler.jsonc is never rewritten by upgrade');
	assert.equal(readFileSync(join(target, 'my-notes.md'), 'utf8'), 'not the runtime\'s, left alone\n', 'unknown files are left alone');
	assert.deepEqual(JSON.parse(readFileSync(join(target, RUNTIME_STAMP), 'utf8')), stamp, 'the stamp is rewritten');

	// Idempotent: a second run at the same release is a no-op in git terms.
	const again = run('npm', upgradeArgs, { env: npmEnvironment });
	assert.match(again.stdout, /is already .*; runtime-owned files rewritten/);
	assert.match(again.stdout, /removed \(no longer shipped\): none/);

	// A deleted user-owned file is seeded from the template.
	rmSync(join(target, 'wrangler.jsonc'));
	const seeded = run('npm', upgradeArgs, { env: npmEnvironment });
	assert.match(seeded.stdout, /left alone \(yours\): none/);
	assert.ok(existsSync(join(target, 'wrangler.jsonc')));

	// The committed stamp is user-controlled input and the only thing read
	// from it is the list of files to consider for removal. Removal is
	// confined to a plain file at a canonical relative path under the
	// directory: a path that escapes, a symlink, or a directory is left
	// where it is, and nothing outside the directory is touched.
	const victim = join(temporaryRoot, 'victim.txt');
	writeFileSync(victim, 'not yours\n');
	const outsideDir = join(temporaryRoot, 'outside-dir');
	mkdirSync(outsideDir);
	writeFileSync(join(outsideDir, 'keep.txt'), 'keep\n');
	mkdirSync(join(target, 'stale-dir'));
	writeFileSync(join(target, 'stale-dir', 'inner.txt'), 'inner\n');
	symlinkSync(victim, join(target, 'stale-link.txt'));
	symlinkSync(outsideDir, join(target, 'stale-dir-link'));
	const hostileStamp = {
		...stamp,
		version: '0.0.2-older',
		runtime_owned: [
			...stamp.runtime_owned,
			'../victim.txt',
			`/${victim.replace(/^\//, '')}`,
			'src/../../victim.txt',
			'stale-link.txt',
			'stale-dir',
			'stale-dir-link',
			'stale-dir-link/keep.txt',
		],
	};
	writeFileSync(join(target, RUNTIME_STAMP), `${JSON.stringify(hostileStamp, null, 2)}\n`);
	const hostile = run('npm', upgradeArgs, { env: npmEnvironment });
	assert.match(hostile.stdout, /removed \(no longer shipped\): none/);
	assert.equal(readFileSync(victim, 'utf8'), 'not yours\n', 'a stamp path must not reach outside the directory');
	assert.equal(readFileSync(join(outsideDir, 'keep.txt'), 'utf8'), 'keep\n', 'a symlinked directory must not be traversed');
	assert.ok(lstatSync(join(target, 'stale-link.txt')).isSymbolicLink(), 'a symlink is not removed');
	assert.ok(lstatSync(join(target, 'stale-dir-link')).isSymbolicLink());
	assert.equal(readFileSync(join(target, 'stale-dir', 'inner.txt'), 'utf8'), 'inner\n', 'a directory is not removed');
	assert.deepEqual(JSON.parse(readFileSync(join(target, RUNTIME_STAMP), 'utf8')), stamp);
	rmSync(join(target, 'stale-link.txt'));
	rmSync(join(target, 'stale-dir-link'));
	rmSync(join(target, 'stale-dir'), { recursive: true });

	// A directory with no stamp is not upgradable: the old scaffold has no
	// record of what is whose, and guessing is how a user file gets clobbered.
	const unstamped = join(temporaryRoot, 'unstamped');
	mkdirSync(unstamped);
	writeFileSync(join(unstamped, 'wrangler.jsonc'), '{}\n');
	const refuseUnstamped = run('npm', ['exec', '--yes', `--package=${tarball}`, '--', 'atoms-runtime-cloudflare', 'upgrade', unstamped], { expectFailure: true, env: npmEnvironment });
	assert.notEqual(refuseUnstamped.status, 0);
	assert.match(refuseUnstamped.stderr, new RegExp(`has no ${RUNTIME_STAMP}`));
	assert.equal(readFileSync(join(unstamped, 'wrangler.jsonc'), 'utf8'), '{}\n');

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
	assert.match(secondRun.stderr, /atoms-runtime-cloudflare upgrade/, 'init on a scaffolded directory must point at upgrade');

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

	console.log('runtime package allowlist, local-tarball scaffold and upgrade: ok');
} finally {
	rmSync(temporaryRoot, { recursive: true, force: true });
}
