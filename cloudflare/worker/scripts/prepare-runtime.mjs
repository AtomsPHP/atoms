#!/usr/bin/env node

// The scaffold owns this pin. Never take expected digests from the installed
// interpreter package: a mixed package must fail even if its metadata agrees.
import { createHash } from 'node:crypto';
import { existsSync, mkdirSync, mkdtempSync, readFileSync, renameSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath(new URL('../', import.meta.url));
const pin = JSON.parse(readFileSync(join(root, 'release/interpreter.json'), 'utf8'));
const pkg = join(root, 'node_modules', pin.package);
const identity = `${pin.package}@${pin.version}`;

function verify(label, bytes, expected) {
	const hash = createHash('sha256').update(bytes).digest('hex');
	if (bytes.length !== expected.bytes || hash !== expected.sha256) {
		throw new Error(`atoms: ${identity} ${label} mismatch. Expected ${expected.bytes} bytes, SHA-256 ${expected.sha256}. Got ${bytes.length} bytes, SHA-256 ${hash}.`);
	}
}

// npm runs prepare during lockfile-only operations. A normal install or an
// explicit prepare-runtime must fail even when the entire package is absent.
if (process.argv.includes('--if-installed') && process.env.npm_config_package_lock_only === 'true' && !existsSync(pkg)) {
	process.exit(0);
}

const manifest = JSON.parse(readFileSync(join(pkg, 'package.json'), 'utf8'));
if (manifest.name !== pin.package || manifest.version !== pin.version) {
	throw new Error(`atoms: expected ${identity}, found ${manifest.name}@${manifest.version}.`);
}
const wasm = readFileSync(join(pkg, pin.wasm.path));
let glue = readFileSync(join(pkg, pin.glue.path));
verify('wasm', wasm, pin.wasm);
verify('glue', glue, pin.glue);

// Playground keeps Asyncify module-private. The host needs to resume the
// guest on its caller's JS stack inside transactionSync. Atoms builds ship
// this line in the final, hashed glue.
if (pin.package === '@php-wasm/web-8-3') {
	const anchor = '\tvar getCFunc = (ident) => {';
	const exposure = "Module['Asyncify'] = Asyncify;";
	const source = glue.toString('utf8');
	if (source.split(anchor).length !== 2 || source.includes(exposure)) {
		throw new Error('atoms: review the Playground Asyncify patch site before updating the pin.');
	}
	glue = Buffer.from(source.replace(anchor, `\t${exposure}\n${anchor}`));
	verify('staged glue', glue, pin.stagedGlue);
}

// One rename publishes the verified pair to Wrangler. Both files keep their
// package-relative paths so the glue imports the exact precompiled module.
const out = join(root, '.php-wasm');
mkdirSync(out, { recursive: true });
const temp = mkdtempSync(join(out, '.prepare-'));
try {
	const pair = `${pin.wasm.sha256}-${(pin.stagedGlue ?? pin.glue).sha256}`;
	for (const [path, bytes] of [[pin.wasm.path, wasm], [pin.glue.path, glue]]) {
		const destination = join(out, pair, path);
		mkdirSync(dirname(destination), { recursive: true });
		const file = join(temp, 'file');
		writeFileSync(file, bytes);
		renameSync(file, destination);
	}
	const entry = join(temp, 'runtime.js');
	const specifier = JSON.stringify(`./${pair}/${pin.glue.path}`);
	writeFileSync(entry, `export * from ${specifier};\n`);
	renameSync(entry, join(out, 'runtime.js'));
} finally {
	rmSync(temp, { recursive: true, force: true });
}

console.log(`${identity} staged (${wasm.length} bytes Wasm, ${glue.length} bytes glue).`);
