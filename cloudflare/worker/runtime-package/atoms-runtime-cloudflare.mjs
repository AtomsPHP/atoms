#!/usr/bin/env node

import {
	constants,
	copyFileSync,
	existsSync,
	lstatSync,
	mkdirSync,
	mkdtempSync,
	readdirSync,
	renameSync,
	rmdirSync,
	rmSync,
} from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const packageRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');

function usage() {
	console.error('Usage: atoms-runtime-cloudflare init <target-directory>');
}

function filesUnder(root, prefix = '') {
	const result = [];
	for (const entry of readdirSync(join(root, prefix), { withFileTypes: true })) {
		const relative = join(prefix, entry.name);
		if (entry.isDirectory()) result.push(...filesUnder(root, relative));
		else if (entry.isFile()) result.push(relative);
		else throw new Error(`atoms: runtime template contains unsupported entry ${relative}`);
	}
	return result.sort();
}

function scaffold(targetArg) {
	const target = resolve(targetArg);
	const targetExists = existsSync(target);
	if (targetExists) {
		if (!lstatSync(target).isDirectory()) {
			throw new Error(`atoms: scaffold target is not a directory: ${target}`);
		}
		if (readdirSync(target).length !== 0) {
			throw new Error(`atoms: scaffold target is not empty; refusing to overwrite: ${target}`);
		}
	}
	mkdirSync(dirname(target), { recursive: true });
	const staging = mkdtempSync(join(dirname(target), '.atoms-runtime-'));

	const sources = [
		...filesUnder(join(packageRoot, 'template')).map((file) => ({
			source: join(packageRoot, 'template', file),
			destination: file === 'gitignore' ? '.gitignore' : file,
		})),
		{ source: join(packageRoot, 'README.md'), destination: 'README.md' },
		{ source: join(packageRoot, 'LICENSE'), destination: 'LICENSE' },
		{ source: join(packageRoot, 'THIRD_PARTY_NOTICES.md'), destination: 'THIRD_PARTY_NOTICES.md' },
	];

	try {
		for (const { source, destination } of sources) {
			const output = join(staging, destination);
			mkdirSync(dirname(output), { recursive: true });
			copyFileSync(source, output, constants.COPYFILE_EXCL);
		}
		if (targetExists) rmdirSync(target);
		renameSync(staging, target);
	} finally {
		rmSync(staging, { recursive: true, force: true });
	}

	console.log(`Atoms Cloudflare runtime initialized in ${target}`);
	console.log(`Next: cd ${targetArg} && npm ci`);
}

const [command, target, ...extra] = process.argv.slice(2);
if (command !== 'init' || !target || extra.length !== 0) {
	usage();
	process.exit(2);
}

try {
	scaffold(target);
} catch (error) {
	console.error(error instanceof Error ? error.message : String(error));
	process.exit(1);
}
