#!/usr/bin/env node

/**
 * Build the public @atomsphp/runtime-cloudflare package from an explicit list
 * of production Worker inputs. The staging directory begins empty, so a new
 * fixture, test, generated customer bundle, cache, or wasm artifact cannot
 * enter the tarball merely by appearing under cloudflare/worker.
 *
 * The JavaScript half of that list is *derived*, not hand-maintained: every
 * module reachable from an entrypoint below is packaged, so adding a module
 * cannot silently ship a package whose imports do not resolve. Everything a
 * module graph cannot see — PHP sources, the scaffold config, dotfiles — is
 * still enumerated by hand, and what is deliberately withheld is named in
 * UNPACKAGED_MODULES / UNPACKAGED_PREFIXES rather than merely left off a list.
 */

import { spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import {
	cpSync,
	existsSync,
	mkdtempSync,
	mkdirSync,
	readdirSync,
	readFileSync,
	rmSync,
	writeFileSync,
} from 'node:fs';
import { dirname, join, relative, resolve, sep } from 'node:path';
import { tmpdir } from 'node:os';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const workerRoot = resolve(here, '..');
const cloudflareRoot = resolve(workerRoot, '..');
const repoRoot = resolve(cloudflareRoot, '..');

/** Roots of the shipped module graph; everything they reach is packaged. */
const PACKAGE_ENTRYPOINTS = [
	'src/index.js',
	'scripts/bundle-from-cli.mjs',
	'scripts/prepare-runtime.mjs',
];

/** Imported by shipped code, deliberately not shipped. */
const UNPACKAGED_MODULES = new Set([
	// Written per project by scripts/bundle-from-cli.mjs out of the customer's
	// `atoms build` output. The repository's own copy is the conformance
	// fixture's bundle and must never enter the tarball.
	'src/bundle.generated.js',
]);

/** Directory prefixes imported by shipped code, deliberately not shipped. */
const UNPACKAGED_PREFIXES = [
	// The GPL php-wasm binary and its glue: fetched from npm and staged by
	// scripts/prepare-runtime.mjs at install time, never redistributed here.
	'.php-wasm/',
];

/** Non-module inputs, which no import graph can discover. */
const STATIC_FILES = [
	// Ships as the template's .gitignore. The repository's own .gitignore is
	// the monorepo's (it commits src/bundle.generated.js as the conformance
	// fixture, which a customer directory must never do) and stays here.
	'gitignore.scaffold',
	'php/atoms-core',
	'php/runtime',
	'release/supported-core',
	// Ships as the template's wrangler.jsonc. The repository's own
	// wrangler.jsonc is the conformance-harness config (debug endpoints on,
	// name atoms-conformance) and deliberately never enters the tarball.
	'wrangler.scaffold.jsonc',
];

/**
 * The ownership split of a scaffolded Worker directory, recorded in its
 * `atoms-runtime.json` stamp. Everything the template ships is runtime-owned
 * — rewritten by `atoms-runtime-cloudflare upgrade` — except the files named
 * here, which `init` writes once and `upgrade` never touches. Keep this list
 * in step with the "What you own" table in runtime-package/README.md.
 */
export const USER_OWNED_FILES = ['wrangler.jsonc'];

/** The stamp's file name; the Atoms CLI reads `version` out of it (ATOMS-E108). */
export const RUNTIME_STAMP = 'atoms-runtime.json';

/**
 * npm strips `.gitignore` from a published package, so the template carries
 * it as `gitignore` and `init`/`upgrade` restore the dot. The stamp records
 * the scaffolded name.
 */
export const TEMPLATE_RENAMES = { gitignore: '.gitignore' };

// `from '…'`, `import '…'`, `import('…')` — and the `import('./x.js')` inside a
// JSDoc type, which is a real dependency of anyone type-checking the package.
const SPECIFIER = /(?:\bfrom\s*|\bimport\s*\(?\s*)['"]([^'"]+)['"]/g;

function toPosix(path) {
	return path.split(sep).join('/');
}

/**
 * Walk the static import graph from `entrypoints`, returning every reachable
 * module as a worker-root-relative POSIX path. Throws on an import that does
 * not resolve to a file, which is the failure this replaced.
 */
export function packagedModules(entrypoints = PACKAGE_ENTRYPOINTS) {
	const found = new Set();
	const queue = [...entrypoints];
	while (queue.length > 0) {
		const current = queue.shift();
		if (found.has(current)) continue;
		const absolute = join(workerRoot, current);
		if (!existsSync(absolute)) {
			throw new Error(`packaged module does not exist: ${current}`);
		}
		found.add(current);

		const source = readFileSync(absolute, 'utf8');
		for (const [, specifier] of source.matchAll(SPECIFIER)) {
			if (!specifier.startsWith('.')) continue; // a bare specifier is a dependency
			const target = toPosix(relative(workerRoot, resolve(dirname(absolute), specifier)));
			if (target.startsWith('..')) {
				throw new Error(`${current} imports outside cloudflare/worker: ${specifier}`);
			}
			if (UNPACKAGED_MODULES.has(target)) continue;
			if (UNPACKAGED_PREFIXES.some((prefix) => target.startsWith(prefix))) continue;
			queue.push(target);
		}
	}
	return [...found].sort();
}

const PRODUCTION_FILES = [...STATIC_FILES, ...packagedModules()].sort();

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

	const RENAMES = {
		'gitignore.scaffold': 'gitignore',
		'wrangler.scaffold.jsonc': 'wrangler.jsonc',
	};
	for (const file of PRODUCTION_FILES) {
		const destination = RENAMES[file] ?? file;
		copy(join(workerRoot, file), join(stageRoot, 'template', destination));
	}
	for (const [source, destination] of ROOT_PACKAGE_FILES) {
		copy(join(cloudflareRoot, source), join(stageRoot, destination));
	}
	// The template is the whole scaffold: the licence and notices ship inside
	// the directory a customer commits, not only at the package root.
	for (const file of ['LICENSE', 'THIRD_PARTY_NOTICES.md']) {
		copy(join(stageRoot, file), join(stageRoot, 'template', file));
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
	writeFileSync(join(stageRoot, 'template', 'README.md'), readme);

	// Last, because it hashes everything above: the stamp `init` copies into
	// the scaffold and `upgrade` reads back. Paths are recorded as they land
	// in a scaffolded directory (`.gitignore`, not `gitignore`), sorted, so
	// the stamp is a deterministic function of the template.
	const stamp = runtimeStamp(join(stageRoot, 'template'), release.runtime);
	writeFileSync(join(stageRoot, 'template', RUNTIME_STAMP), `${JSON.stringify(stamp, null, 2)}\n`);

	return { packageManifest, templateManifest, stamp };
}

/**
 * @param {string} templateRoot
 * @param {{package: string, version: string}} runtime
 */
export function runtimeStamp(templateRoot, runtime) {
	const runtimeOwned = {};
	for (const file of filesUnder(templateRoot)) {
		const scaffolded = TEMPLATE_RENAMES[file] ?? file;
		if (USER_OWNED_FILES.includes(scaffolded)) continue;
		runtimeOwned[scaffolded] = createHash('sha256').update(readFileSync(join(templateRoot, file))).digest('hex');
	}
	return {
		$comment:
			'Written by `atoms-runtime-cloudflare init` and `upgrade`; do not edit. The Atoms CLI reads '
			+ '`version` to refuse deploying a Worker directory that does not match its own release '
			+ '(ATOMS-E108). `upgrade` reads `runtime_owned` to know which files it rewrites and which '
			+ 'stale ones it removes, and leaves every `user_owned` file alone.',
		package: runtime.package,
		version: runtime.version,
		runtime_owned: runtimeOwned,
		user_owned: [...USER_OWNED_FILES].sort(),
	};
}

/** Every file under `root`, as sorted root-relative POSIX paths. */
function filesUnder(root, prefix = '') {
	const result = [];
	for (const entry of readdirSync(join(root, prefix), { withFileTypes: true })) {
		const relative = prefix === '' ? entry.name : `${prefix}/${entry.name}`;
		if (entry.isDirectory()) result.push(...filesUnder(root, relative));
		else if (entry.isFile()) result.push(relative);
		else throw new Error(`runtime template contains unsupported entry ${relative}`);
	}
	return result.sort();
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
