#!/usr/bin/env node
import { createHash } from 'node:crypto';
import { existsSync, mkdirSync, mkdtempSync, readdirSync, readFileSync, renameSync, rmSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { PHP_RUNTIMES } from './lib/php-runtimes.mjs';

const worker = fileURLToPath(new URL('../', import.meta.url));
const packages = join(worker, 'node_modules');
const out = join(worker, '.php-wasm');
const pins = Object.values(PHP_RUNTIMES);

function readFile(pkg, expected) {
	const path = join(pkg, expected.path);
	const bytes = readFileSync(path);
	const hash = createHash('sha256').update(bytes).digest('hex');
	if (bytes.length !== expected.bytes || hash !== expected.sha256) {
		throw new Error(`atoms: pinned artifact mismatch for ${path}. Expected ${expected.bytes} bytes, SHA-256 ${expected.sha256}. Got ${bytes.length} bytes, SHA-256 ${hash}.`);
	}
	return bytes;
}

function verify(pin) {
	const pkg = join(packages, pin.package);
	const manifest = JSON.parse(readFileSync(join(pkg, 'package.json'), 'utf8'));
	if (manifest.name !== pin.package || manifest.version !== pin.version) {
		throw new Error(`atoms: expected ${pin.package}@${pin.version}, found ${manifest.name}@${manifest.version}.`);
	}
	return { pin, wasm: readFile(pkg, pin.wasm), glue: readFile(pkg, pin.glue) };
}

// npm install --package-lock-only can run prepare before installing dependencies.
if (process.argv.includes('--if-installed') && process.env.npm_config_package_lock_only === 'true' &&
	pins.every(pin => !existsSync(join(packages, pin.package)))) {
	console.log('PHP interpreters will be staged on the next npm ci.');
} else {
	// Verify all installed packages before writing anything. Wrangler can follow
	// each entry as soon as it exists, so write its entire pair first and then
	// rename the per-minor re-export over the old entry.
	const verified = pins.map(verify);
	mkdirSync(out, { recursive: true });
	const temp = mkdtempSync(join(out, '.prepare-'));
	const keep = new Set();
	try {
		for (const { pin, wasm, glue } of verified) {
			const pair = `${pin.wasm.sha256}-${pin.glue.sha256}`;
			mkdirSync(join(out, pair), { recursive: true });
			for (const [file, bytes] of [[pin.wasm.path, wasm], [pin.glue.path, glue]]) {
				writeFileSync(join(temp, file), bytes);
				renameSync(join(temp, file), join(out, pair, file));
			}
			const loader = pin.glue.path;
			writeFileSync(join(temp, loader), `export * from ${JSON.stringify(`./${pair}/${loader}`)};\n`);
			keep.add(pair);
			keep.add(loader);
		}
		for (const { pin } of verified) {
			renameSync(join(temp, pin.glue.path), join(out, pin.glue.path));
			console.log(`Staged ${pin.package}@${pin.version}.`);
		}
		for (const entry of readdirSync(out)) {
			if (!keep.has(entry) && !entry.startsWith('.prepare-')) rmSync(join(out, entry), { recursive: true, force: true });
		}
	} finally {
		rmSync(temp, { recursive: true, force: true });
	}
}
