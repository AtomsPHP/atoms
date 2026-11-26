// Package a local review scaffold. The release packer always uses its pinned
// registry dependencies. This copy uses an absolute file dependency for npm ci.
import { execFileSync } from 'node:child_process';
import { mkdtempSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { stageRuntimePackage } from '../worker/scripts/pack-runtime.mjs';

const [pinFile, tarball, out] = process.argv.slice(2);
if (!out) throw new Error('Usage: node qualification-scaffold.mjs <reviewed interpreter.json> <candidate.tgz> <destination>');
const read = (path) => JSON.parse(readFileSync(path, 'utf8'));
const write = (path, value) => writeFileSync(path, JSON.stringify(value, null, 2) + '\n');
const pin = read(pinFile);
if (pin.package !== '@atomsphp/php-wasm-8-3') throw new Error('Expected an Atoms PHP 8.3 candidate pin.');
const dest = resolve(out);
mkdirSync(dest, { recursive: true });
const stage = mkdtempSync(join(dest, '.scaffold-'));
try {
	stageRuntimePackage(stage);
	const template = join(stage, 'template');
	const selected = join(template, 'release/interpreter.json');
	const manifestPath = join(template, 'package.json');
	const manifest = read(manifestPath);
	delete manifest.dependencies[read(selected).package];
	manifest.dependencies[pin.package] = 'file:' + resolve(tarball);
	write(manifestPath, manifest);
	write(selected, pin);
	execFileSync('npm', ['install', '--package-lock-only', '--ignore-scripts', '--offline', '--no-audit', '--no-fund'], {
		cwd: template, stdio: 'inherit',
	});
	const lockPath = join(template, 'package-lock.json');
	const lock = read(lockPath);
	const dependency = lock.packages[`node_modules/${pin.package}`];
	if (dependency?.version !== pin.version || !dependency.integrity) {
		throw new Error('The locked tarball must match the reviewed version and have npm integrity.');
	}
	// npm computes a relative resolved path from this temporary template.
	// init and upgrade copy the lockfile to a different application directory.
	dependency.resolved = manifest.dependencies[pin.package];
	write(lockPath, lock);
	const [packed] = JSON.parse(execFileSync('npm', ['pack', '--ignore-scripts', '--json', '--pack-destination', dest], {
		cwd: stage, encoding: 'utf8',
	}));
	console.log(join(dest, packed.filename));
} finally {
	rmSync(stage, { recursive: true, force: true });
}
