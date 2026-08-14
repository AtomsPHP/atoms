#!/usr/bin/env node

/**
 * Build the public @atomsphp/runtime-cloudflare package from an explicit list
 * of production Worker inputs. The staging directory begins empty, so a new
 * fixture, test, generated customer bundle, cache, or wasm artifact cannot
 * enter the tarball merely by appearing under cloudflare/worker.
 */

import { spawnSync } from 'node:child_process';
import {
	cpSync,
	mkdtempSync,
	mkdirSync,
	readFileSync,
	rmSync,
	writeFileSync,
} from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { tmpdir } from 'node:os';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const workerRoot = resolve(here, '..');
const cloudflareRoot = resolve(workerRoot, '..');
const repoRoot = resolve(cloudflareRoot, '..');

const PRODUCTION_FILES = [
	'.gitignore',
	'php/atoms-core',
	'php/runtime',
	'release/supported-core',
	'scripts/bundle-from-cli.mjs',
	'scripts/prepare-runtime.mjs',
	'src/atom-do.js',
	'src/bridge.js',
	'src/callbacks.js',
	'src/config.js',
	'src/errors.js',
	'src/index.js',
	'src/int64.js',
	'src/php-host.js',
	'src/timers.js',
	'src/websockets.js',
	'wrangler.jsonc',
];

const ROOT_PACKAGE_FILES = [
	['LICENSE-MIT', 'LICENSE'],
	['THIRD_PARTY_NOTICES.md', 'THIRD_PARTY_NOTICES.md'],
	['corresponding-source', 'corresponding-source'],
];

function readJson(path) {
	return JSON.parse(readFileSync(path, 'utf8'));
}

function copy(source, destination) {
	mkdirSync(dirname(destination), { recursive: true });
	cpSync(source, destination, { recursive: true, errorOnExist: true, force: false });
}

function runtimeManifest(release, developmentManifest) {
	return {
		name: 'atoms-cloudflare-worker',
		private: true,
		type: 'module',
		version: release.runtime.version,
		description: 'Deployable Atoms PHP runtime for Cloudflare Workers.',
		license: 'MIT',
		engines: { node: `>=${release.runtime.node}` },
		dependencies: developmentManifest.dependencies,
		devDependencies: developmentManifest.devDependencies,
		scripts: {
			prepare: 'node scripts/prepare-runtime.mjs --if-installed',
			'prepare-runtime': 'node scripts/prepare-runtime.mjs',
			'bundle:cli': 'node scripts/bundle-from-cli.mjs',
			dev: 'wrangler dev',
			deploy: 'wrangler deploy',
		},
	};
}

export function stageRuntimePackage(stageRoot) {
	const release = readJson(join(repoRoot, 'release', 'manifest.json'));
	const developmentManifest = readJson(join(workerRoot, 'package.json'));
	const lock = readJson(join(workerRoot, 'package-lock.json'));

	if (release.runtime.package !== '@atomsphp/runtime-cloudflare') {
		throw new Error(`unexpected runtime package in release manifest: ${release.runtime.package}`);
	}
	if (developmentManifest.dependencies['@php-wasm/web-8-3'] !== '3.1.48') {
		throw new Error('the php-wasm pin moved; update and re-audit prepare-runtime.mjs before packaging');
	}
	if (developmentManifest.devDependencies.wrangler !== release.runtime.wrangler) {
		throw new Error('Wrangler differs between cloudflare/worker and release/manifest.json');
	}

	rmSync(stageRoot, { recursive: true, force: true });
	mkdirSync(join(stageRoot, 'bin'), { recursive: true });
	mkdirSync(join(stageRoot, 'template'), { recursive: true });

	copy(
		join(workerRoot, 'runtime-package', 'atoms-runtime-cloudflare.mjs'),
		join(stageRoot, 'bin', 'atoms-runtime-cloudflare.mjs'),
	);

	for (const relative of PRODUCTION_FILES) {
		const destination = relative === '.gitignore' ? 'gitignore' : relative;
		copy(join(workerRoot, relative), join(stageRoot, 'template', destination));
	}
	for (const [source, destination] of ROOT_PACKAGE_FILES) {
		copy(join(cloudflareRoot, source), join(stageRoot, destination));
	}
	const provenanceReadme = join(stageRoot, 'corresponding-source', 'README.md');
	writeFileSync(
		provenanceReadme,
		readFileSync(provenanceReadme, 'utf8').replaceAll('../worker/', '../template/'),
	);

	const templateManifest = runtimeManifest(release, developmentManifest);
	writeFileSync(join(stageRoot, 'template', 'package.json'), `${JSON.stringify(templateManifest, null, 2)}\n`);

	lock.name = templateManifest.name;
	lock.version = templateManifest.version;
	lock.packages[''] = {
		...lock.packages[''],
		name: templateManifest.name,
		version: templateManifest.version,
		license: templateManifest.license,
		engines: templateManifest.engines,
		dependencies: templateManifest.dependencies,
		devDependencies: templateManifest.devDependencies,
	};
	writeFileSync(join(stageRoot, 'template', 'package-lock.json'), `${JSON.stringify(lock, null, 2)}\n`);

	const packageManifest = {
		name: release.runtime.package,
		version: release.runtime.version,
		description: 'Scaffold the Atoms PHP runtime for Cloudflare Workers.',
		license: 'MIT',
		type: 'module',
		engines: { node: `>=${release.runtime.node}` },
		bin: { 'atoms-runtime-cloudflare': 'bin/atoms-runtime-cloudflare.mjs' },
		files: [
			'bin/',
			'template/',
			'README.md',
			'LICENSE',
			'THIRD_PARTY_NOTICES.md',
			'corresponding-source/',
		],
		repository: {
			type: 'git',
			url: 'git+https://github.com/AtomsPHP/atoms.git',
			directory: 'cloudflare/worker',
		},
		bugs: { url: 'https://github.com/AtomsPHP/atoms/issues' },
		homepage: 'https://docs.atomsphp.dev/',
		publishConfig: { access: 'public', provenance: true },
	};
	writeFileSync(join(stageRoot, 'package.json'), `${JSON.stringify(packageManifest, null, 2)}\n`);

	const readme = readFileSync(join(workerRoot, 'runtime-package', 'README.md'), 'utf8')
		.replaceAll('@VERSION@', release.runtime.version);
	writeFileSync(join(stageRoot, 'README.md'), readme);

	return { packageManifest, templateManifest };
}

function option(name, fallback) {
	const index = process.argv.indexOf(name);
	return index === -1 ? fallback : process.argv[index + 1];
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
	const packDestination = resolve(option('--pack-destination', join(workerRoot, 'dist')));
	const temporaryRoot = mkdtempSync(join(tmpdir(), 'atoms-runtime-package-'));
	const stageRoot = join(temporaryRoot, 'package');
	try {
		stageRuntimePackage(stageRoot);
		mkdirSync(packDestination, { recursive: true });
		const packed = spawnSync('npm', ['pack', stageRoot, '--pack-destination', packDestination], {
			encoding: 'utf8',
			stdio: ['ignore', 'pipe', 'inherit'],
		});
		if (packed.status !== 0) process.exit(packed.status ?? 1);
		const filename = packed.stdout.trim().split(/\r?\n/).at(-1);
		console.log(join(packDestination, filename));
		console.log(`publish with: npm publish ${join(packDestination, filename)} --access public --provenance`);
	} finally {
		rmSync(temporaryRoot, { recursive: true, force: true });
	}
}
