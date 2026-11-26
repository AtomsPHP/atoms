#!/usr/bin/env node

/**
 * Verify and stage each pinned Playground Asyncify interpreter in .php-wasm/.
 * Preserve the package layout so the loader's relative Wasm import resolves.
 * The generated Worker bundle imports only the loader its manifest selects.
 */

import { createHash } from 'node:crypto';
import { existsSync, mkdirSync, readdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { PHP_RUNTIMES, PLAYGROUND_VERSION, phpRuntime } from './lib/php-runtimes.mjs';

const workerRoot = dirname(dirname(fileURLToPath(import.meta.url)));
const outDir = join(workerRoot, '.php-wasm');
const runtimes = Object.keys(PHP_RUNTIMES).map(phpRuntime);

// Emscripten keeps Asyncify module-private. The host owns the wakeUp callback
// so it can resume PHP in a synchronous ctx.storage.transactionSync callback.
const PATCH_ANCHOR = '\tvar getCFunc = (ident) => {';
const PATCH_LINE = "\tModule['Asyncify'] = Asyncify;\n";

function readVerified(pkgRoot, file, expectedBytes, expectedHash) {
	const path = join(pkgRoot, 'asyncify', file);
	const bytes = readFileSync(path);
	const actualHash = createHash('sha256').update(bytes).digest('hex');
	if (bytes.length !== expectedBytes || actualHash !== expectedHash) {
		throw new Error(
			`atoms: pinned artifact mismatch for ${path}\n` +
			`  expected ${expectedBytes} bytes, SHA-256 ${expectedHash}\n` +
			`  got      ${bytes.length} bytes, SHA-256 ${actualHash}`,
		);
	}
	return bytes;
}

function verify(runtime) {
	const pkgRoot = join(workerRoot, 'node_modules', runtime.packageName);
	const { version } = JSON.parse(readFileSync(join(pkgRoot, 'package.json'), 'utf8'));
	if (version !== PLAYGROUND_VERSION) {
		throw new Error(`atoms: expected ${runtime.packageName}@${PLAYGROUND_VERSION}, found ${version}`);
	}
	const wasm = readVerified(pkgRoot, runtime.wasm, runtime.wasmBytes, runtime.wasmSha256);
	const glue = readVerified(pkgRoot, runtime.glue, runtime.glueBytes, runtime.glueSha256).toString('utf8');
	if (!glue.includes(PATCH_ANCHOR) || glue.includes(PATCH_LINE.trim())) {
		throw new Error(`atoms: re-audit the Asyncify patch for ${runtime.packageName}; its patch site changed.`);
	}
	return { runtime, wasm, glue: glue.replace(PATCH_ANCHOR, PATCH_LINE + PATCH_ANCHOR) };
}

// npm install --package-lock-only also runs prepare, with no dependencies.
// A partial install or a missing file in an installed package must still fail.
if (process.argv.includes('--if-installed') && runtimes.every(({ packageName }) =>
	!existsSync(join(workerRoot, 'node_modules', packageName)))) {
	console.log('PHP runtimes will be staged on the next npm ci.');
} else {
	// Validate every pair before replacing any existing staged files.
	for (const { runtime, wasm, glue } of runtimes.map(verify)) {
		const wasmPath = join(outDir, runtime.wasm);
		mkdirSync(dirname(wasmPath), { recursive: true });
		writeFileSync(wasmPath, wasm);
		writeFileSync(join(outDir, runtime.glue), glue);
		console.log(`Staged PHP ${runtime.version} from ${runtime.packageName}@${PLAYGROUND_VERSION}.`);
	}

	// Each patch version has its own Wasm directory. Prune superseded files
	// only after every loader and binary for this release is in place.
	const current = new Set(runtimes.flatMap(runtime => [runtime.glue, dirname(runtime.wasm)]));
	for (const entry of readdirSync(outDir)) {
		if (!current.has(entry)) rmSync(join(outDir, entry), { recursive: true, force: true });
	}
}
