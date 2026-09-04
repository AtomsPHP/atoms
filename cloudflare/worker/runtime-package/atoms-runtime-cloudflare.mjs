#!/usr/bin/env node

/**
 * The `@atomsphp/runtime-cloudflare` binary: `init` scaffolds a Worker
 * directory that you commit, and `upgrade` moves a committed one to this
 * package's release.
 *
 * The directory is co-versioned with the Atoms CLI and Composer packages, and
 * the two halves are kept honest by the `atoms-runtime.json` stamp this
 * script writes: the CLI refuses to deploy or run a directory whose stamp
 * names another release (ATOMS-E108), and `upgrade` is the answer that error
 * prints. The stamp also lists which files the runtime owns, so the next
 * `upgrade` can remove the ones a release stopped shipping.
 */

import {
	constants,
	copyFileSync,
	existsSync,
	lstatSync,
	mkdirSync,
	mkdtempSync,
	readdirSync,
	readFileSync,
	renameSync,
	rmdirSync,
	rmSync,
	unlinkSync,
} from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const packageRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const templateRoot = join(packageRoot, 'template');

/** The stamp file at the root of every scaffolded directory. */
const RUNTIME_STAMP = 'atoms-runtime.json';

/**
 * npm strips `.gitignore` from a published package, so the template carries
 * it as `gitignore`; the stamp and the scaffold use the real name.
 */
const TEMPLATE_RENAMES = { gitignore: '.gitignore' };

function usage() {
	console.error('Usage: atoms-runtime-cloudflare init <target-directory>');
	console.error('       atoms-runtime-cloudflare upgrade <target-directory>');
}

function filesUnder(root, prefix = '') {
	const result = [];
	for (const entry of readdirSync(join(root, prefix), { withFileTypes: true })) {
		const relative = prefix === '' ? entry.name : `${prefix}/${entry.name}`;
		if (entry.isDirectory()) result.push(...filesUnder(root, relative));
		else if (entry.isFile()) result.push(relative);
		else throw new Error(`atoms: runtime template contains unsupported entry ${relative}`);
	}
	return result.sort();
}

function readStamp(directory, describe) {
	const path = join(directory, RUNTIME_STAMP);
	if (!existsSync(path)) return null;
	let stamp;
	try {
		stamp = JSON.parse(readFileSync(path, 'utf8'));
	} catch (error) {
		throw new Error(`atoms: ${describe} ${path} is not valid JSON: ${error.message}`);
	}
	if (typeof stamp?.version !== 'string' || !Array.isArray(stamp?.runtime_owned) || !Array.isArray(stamp?.user_owned)) {
		throw new Error(`atoms: ${describe} ${path} does not have the expected shape (version, runtime_owned, user_owned)`);
	}
	return stamp;
}

/**
 * The template as a map of scaffolded path → template file. The stamp is
 * ordered last: it is what the CLI reads, so a crash mid-copy leaves the old
 * version in place and the CLI still refusing to deploy the half-moved tree.
 */
function templateFiles() {
	const files = new Map();
	for (const file of filesUnder(templateRoot)) {
		if (file === RUNTIME_STAMP) continue;
		files.set(TEMPLATE_RENAMES[file] ?? file, join(templateRoot, file));
	}
	files.set(RUNTIME_STAMP, join(templateRoot, RUNTIME_STAMP));
	return files;
}

function copyInto(target, relative, source) {
	const destination = join(target, relative);
	mkdirSync(dirname(destination), { recursive: true });
	copyFileSync(source, destination);
}

// ---------------------------------------------------------------------------
// init
// ---------------------------------------------------------------------------

function scaffold(targetArg) {
	const target = resolve(targetArg);
	const targetExists = existsSync(target);
	if (targetExists) {
		if (!lstatSync(target).isDirectory()) {
			throw new Error(`atoms: scaffold target is not a directory: ${target}`);
		}
		if (readdirSync(target).length !== 0) {
			throw new Error(
				`atoms: scaffold target is not empty; refusing to overwrite: ${target}\n`
					+ 'To move an existing Atoms Worker directory to this release, run '
					+ `\`atoms-runtime-cloudflare upgrade ${targetArg}\` instead.`,
			);
		}
	}
	mkdirSync(dirname(target), { recursive: true });
	const staging = mkdtempSync(join(dirname(target), '.atoms-runtime-'));

	try {
		for (const [destination, source] of templateFiles()) {
			const output = join(staging, destination);
			mkdirSync(dirname(output), { recursive: true });
			copyFileSync(source, output, constants.COPYFILE_EXCL);
		}
		if (targetExists) rmdirSync(target);
		renameSync(staging, target);
	} finally {
		rmSync(staging, { recursive: true, force: true });
	}

	const stamp = readStamp(target, 'the scaffolded stamp');
	console.log(`Atoms Cloudflare runtime ${stamp.version} initialized in ${target}`);
	console.log(`Next: cd ${targetArg} && npm ci`);
	console.log(`Then commit ${targetArg}: it is part of your repository from now on.`);
	console.log(`You own ${stamp.user_owned.join(', ')}; every other file is the runtime's and`);
	console.log('`atoms-runtime-cloudflare upgrade` rewrites it. README.md in the directory has the details.');
}

// ---------------------------------------------------------------------------
// upgrade
// ---------------------------------------------------------------------------

/**
 * Every write comes from this package's template, never from the committed
 * stamp. The old stamp is read for one thing only: the list of files the
 * previous release owned, so the ones this release no longer ships can be
 * removed — and removal is confined to a plain, non-symlinked file at a
 * canonical relative path under the directory.
 */
function upgrade(targetArg) {
	const target = resolve(targetArg);
	if (!existsSync(target) || !lstatSync(target).isDirectory()) {
		throw new Error(`atoms: upgrade target is not a directory: ${target}`);
	}

	const previous = readStamp(target, 'the existing stamp');
	if (previous === null) {
		throw new Error(
			`atoms: ${target} has no ${RUNTIME_STAMP}, so this is not an Atoms Worker directory this command can upgrade.\n`
				+ 'A directory scaffolded before stamps existed has no record of which files are the runtime\'s and '
				+ 'which are yours. Scaffold a fresh directory with `atoms-runtime-cloudflare init`, carry your '
				+ 'wrangler.jsonc changes across, and commit that instead.',
		);
	}

	const next = readStamp(templateRoot, 'the template stamp');
	const template = templateFiles();
	const written = [];
	const removed = [];
	const leftAlone = [];

	// User-owned files are seeded only when absent; everything else in the
	// template is written. The stamp is last in the map.
	for (const [path, source] of template) {
		if (next.user_owned.includes(path) && existsSync(join(target, path))) {
			leftAlone.push(path);
			continue;
		}
		copyInto(target, path, source);
		written.push(path);
	}

	// Runtime-owned files the previous release shipped and this one does not.
	for (const path of previous.runtime_owned) {
		if (template.has(path) || !isRemovable(target, path)) continue;
		unlinkSync(join(target, path));
		pruneEmptyDirectories(target, dirname(path));
		removed.push(path);
	}

	console.log(
		previous.version === next.version
			? `Atoms Cloudflare runtime in ${target} is already ${next.version}; runtime-owned files rewritten.`
			: `Atoms Cloudflare runtime in ${target}: ${previous.version} -> ${next.version}`,
	);
	console.log(`  written: ${written.length} file(s)`);
	console.log(`  removed (no longer shipped): ${removed.length === 0 ? 'none' : removed.join(', ')}`);
	console.log(`  left alone (yours): ${leftAlone.length === 0 ? 'none' : leftAlone.join(', ')}`);
	console.log(`Next: cd ${targetArg} && npm ci, review the diff, and commit.`);
}

/**
 * A stale path is removed only when it is a canonical relative path — no
 * absolute, `..`, `.` or empty segments — whose every directory component is
 * a real directory (not a symlink) and whose last component is a plain file.
 * Anything else is left where it is; the committed stamp cannot reach outside
 * the directory, through a link or otherwise.
 */
function isRemovable(target, path) {
	if (typeof path !== 'string' || path.startsWith('/') || path.includes('\\')) return false;
	const segments = path.split('/');
	if (segments.some((segment) => segment === '' || segment === '.' || segment === '..')) return false;
	let current = target;
	for (let i = 0; i < segments.length; i++) {
		current = join(current, segments[i]);
		const stat = lstatSync(current, { throwIfNoEntry: false });
		if (stat === undefined || stat.isSymbolicLink()) return false;
		if (i < segments.length - 1 ? !stat.isDirectory() : !stat.isFile()) return false;
	}
	return true;
}

/** Remove the directories a removal left empty, up to (not including) `root`. */
function pruneEmptyDirectories(root, dir) {
	while (dir !== '.' && dir !== '') {
		const absolute = join(root, dir);
		if (readdirSync(absolute).length !== 0) break;
		rmdirSync(absolute);
		dir = dirname(dir);
	}
}

// ---------------------------------------------------------------------------

const [command, target, ...extra] = process.argv.slice(2);
if (!['init', 'upgrade'].includes(command) || !target || extra.length !== 0) {
	usage();
	process.exit(2);
}

try {
	if (command === 'init') scaffold(target);
	else upgrade(target);
} catch (error) {
	console.error(error instanceof Error ? error.message : String(error));
	process.exit(1);
}
