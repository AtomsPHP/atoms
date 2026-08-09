#!/usr/bin/env node

/**
 * Stage the php-wasm runtime out of node_modules, patched, into `.php-wasm/`.
 *
 * This exists so that the repository does not redistribute Playground's
 * GPL-licensed PHP binary. The artifact is fetched by npm from Playground's
 * own package at install time and staged here at build time; it is never
 * committed. GPLv2 obligations attach to distribution, and staging a file
 * into a gitignored directory on the machine that already downloaded it is
 * not distribution.
 *
 * Two things are staged, and the layout is upstream's rather than ours:
 *
 *   .php-wasm/php_8_3.js            <- asyncify/php_8_3.js
 *   .php-wasm/8_3_32/php_8_3.wasm   <- asyncify/8_3_32/php_8_3.wasm
 *
 * Keeping the nested `8_3_32/` directory is deliberate. Upstream's first line
 * imports `./8_3_32/php_8_3.wasm`, so preserving its layout means that import
 * resolves untouched — which removes one of the two patches the old vendored
 * copy carried. What remains is a single line, below.
 *
 * Run by `npm run prepare-runtime`, and automatically after `npm install` /
 * `npm ci` via the `prepare` lifecycle script.
 */

import { createHash } from 'node:crypto';
import { mkdirSync, readFileSync, writeFileSync, rmSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const workerRoot = join(here, '..');
const pkgRoot = join(workerRoot, 'node_modules', '@php-wasm', 'web-8-3');
const outDir = join(workerRoot, '.php-wasm');

/**
 * The exact artifact this worker is built against. Checked on every run: a
 * silent swap of the interpreter underneath us is precisely the failure the
 * old committed copy made impossible, and dropping the copy must not drop
 * that guarantee with it.
 */
const EXPECTED = {
	wasmSha256: 'eca478d2bad4cae984cd5b5ec39ce42311fcc4d31cf48fce7293e9a5034f1c98',
	wasmBytes: 18309089,
	glueSha256: '719346ea1827cc48c8dd298974649503b7a227a7c0e5c12827db1c993dccf3ac',
	glueBytes: 268881,
	version: '3.1.48',
};

/**
 * The one functional change Atoms makes to upstream's glue.
 *
 * Emscripten keeps `Asyncify` module-private. The host has to call
 * `Asyncify.handleSleep` itself so that it owns the `wakeUp` callback and can
 * choose which JS stack frame the guest resumes from — which is what makes
 * resuming PHP inside a synchronous `ctx.storage.transactionSync()` callback
 * expressible at all. See `../src/php-host.js`.
 */
const PATCH_ANCHOR = '\tvar getCFunc = (ident) => {';
const PATCH_LINE = "\tModule['Asyncify'] = Asyncify;\n";

function sha256(buf) {
	return createHash('sha256').update(buf).digest('hex');
}

function check(what, actual, expected) {
	if (actual !== expected) {
		throw new Error(
			`atoms: ${what} mismatch for @php-wasm/web-8-3.\n` +
				`  expected ${expected}\n` +
				`  got      ${actual}\n` +
				'The installed package is not the pinned artifact. Refusing to build ' +
				'against an interpreter this worker has not been verified against.',
		);
	}
}

const gluePath = join(pkgRoot, 'asyncify', 'php_8_3.js');
const wasmPath = join(pkgRoot, 'asyncify', '8_3_32', 'php_8_3.wasm');

/**
 * npm runs `prepare` in contexts where dependencies are legitimately absent —
 * `npm install --package-lock-only` is the common one. Failing there would
 * make an ordinary lockfile refresh error out. So the lifecycle invocation
 * passes `--if-installed` and skips quietly; a direct
 * `npm run prepare-runtime` still fails loudly, because there the missing
 * package really is the problem the caller needs to hear about.
 */
const skipIfAbsent = process.argv.includes('--if-installed');

let glue, wasm;
try {
	glue = readFileSync(gluePath);
	wasm = readFileSync(wasmPath);
} catch (err) {
	if (skipIfAbsent && err.code === 'ENOENT') {
		console.log(
			'@php-wasm/web-8-3 is not installed yet; skipping runtime staging. ' +
				'It will be staged on the next `npm ci`.',
		);
		process.exit(0);
	}
	throw new Error(
		`atoms: cannot read the php-wasm runtime from ${pkgRoot}.\n` +
			'Run `npm ci` first — the interpreter is fetched from npm rather than ' +
			'committed to this repository.\n' +
			`  (${err.message})`,
	);
}

check('wasm size', wasm.length, EXPECTED.wasmBytes);
check('wasm sha256', sha256(wasm), EXPECTED.wasmSha256);
check('glue size', glue.length, EXPECTED.glueBytes);
check('glue sha256', sha256(glue), EXPECTED.glueSha256);

let glueText = glue.toString('utf8');
if (!glueText.includes(PATCH_ANCHOR)) {
	throw new Error(
		'atoms: cannot find the patch anchor in upstream glue. The Asyncify ' +
			'patch site has moved; re-derive it before bumping the pin.',
	);
}
if (glueText.includes(PATCH_LINE.trim())) {
	throw new Error(
		'atoms: upstream glue already exposes Module[\'Asyncify\']. The patch is ' +
			'no longer needed — remove it rather than applying it twice.',
	);
}
glueText = glueText.replace(PATCH_ANCHOR, PATCH_LINE + PATCH_ANCHOR);

rmSync(outDir, { recursive: true, force: true });
mkdirSync(join(outDir, '8_3_32'), { recursive: true });
writeFileSync(join(outDir, 'php_8_3.js'), glueText);
writeFileSync(join(outDir, '8_3_32', 'php_8_3.wasm'), wasm);

console.log(
	`php-wasm ${EXPECTED.version} staged into .php-wasm/ ` +
		`(wasm ${wasm.length} bytes, verified; glue patched: 1 line)`,
);
