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
 * prints. The stamp also records the ownership split — which files the
 * runtime owns and rewrites here, and which you own and it never touches —
 * so the split is machine-checked rather than remembered.
 */

import { createHash } from 'node:crypto';
import {
	constants,
	copyFileSync,
	existsSync,
	lstatSync,
	mkdirSync,
	mkdtempSync,
	readdirSync,
	readFileSync,
	realpathSync,
	renameSync,
	rmdirSync,
	rmSync,
	unlinkSync,
	writeFileSync,
} from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const packageRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const templateRoot = join(packageRoot, 'template');

/** The stamp file at the root of every scaffolded directory. */
export const RUNTIME_STAMP = 'atoms-runtime.json';

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

function sha256(path) {
	return createHash('sha256').update(readFileSync(path)).digest('hex');
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
	if (typeof stamp?.version !== 'string' || typeof stamp?.runtime_owned !== 'object' || !Array.isArray(stamp?.user_owned)) {
		throw new Error(`atoms: ${describe} ${path} does not have the expected shape (version, runtime_owned, user_owned)`);
	}
	return stamp;
}

/** The template as a map of scaffolded path → template file. */
function templateFiles() {
	const files = new Map();
	for (const file of filesUnder(templateRoot)) {
		files.set(TEMPLATE_RENAMES[file] ?? file, join(templateRoot, file));
	}
	return files;
}

/**
 * Remove a file and any directories it leaves empty, up to (not including)
 * `root`.
 */
function removeFile(root, relative) {
	unlinkSync(join(root, relative));
	let dir = dirname(relative);
	while (dir !== '.' && dir !== '') {
		const absolute = join(root, dir);
		if (readdirSync(absolute).length !== 0) break;
		rmdirSync(absolute);
		dir = dirname(dir);
	}
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
	for (const path of Object.keys(next.runtime_owned)) {
		if (!template.has(path)) throw new Error(`atoms: template stamp names ${path}, which the template does not ship`);
	}

	const report = { updated: [], added: [], unchanged: [], removed: [], modified: [], kept: [], seeded: [], reowned: [] };

	// 1. Runtime-owned files: the template's copy wins. A file the user
	//    changed in place is overwritten too — it is runtime-owned, and the
	//    change is in their git history — but it is named, not silently
	//    replaced.
	for (const [path, expected] of Object.entries(next.runtime_owned)) {
		const source = template.get(path);
		const destination = join(target, path);
		if (existsSync(destination)) {
			const current = sha256(destination);
			if (current === expected) {
				report.unchanged.push(path);
				continue;
			}
			if (previous.user_owned.includes(path)) report.reowned.push(path);
			else if (path in previous.runtime_owned && current !== previous.runtime_owned[path]) report.modified.push(path);
			copyFileSync(source, destination);
			report.updated.push(path);
		} else {
			mkdirSync(dirname(destination), { recursive: true });
			copyFileSync(source, destination);
			report.added.push(path);
		}
	}

	// 2. Runtime-owned files this release no longer ships: removed, so a
	//    renamed module cannot linger and shadow its replacement. A path that
	//    became user-owned is left alone.
	for (const path of Object.keys(previous.runtime_owned)) {
		if (path in next.runtime_owned || next.user_owned.includes(path)) continue;
		if (!existsSync(join(target, path))) continue;
		removeFile(target, path);
		report.removed.push(path);
	}

	// 3. User-owned files: never rewritten; seeded from the template only
	//    when absent (a file this release introduces, or one that was
	//    deleted).
	for (const path of next.user_owned) {
		const destination = join(target, path);
		if (existsSync(destination)) {
			report.kept.push(path);
			continue;
		}
		mkdirSync(dirname(destination), { recursive: true });
		copyFileSync(template.get(path), destination);
		report.seeded.push(path);
	}

	// 4. The stamp itself, last, so a crash above leaves the old version in
	//    place and the CLI still refusing to deploy the half-upgraded tree.
	copyFileSync(join(templateRoot, RUNTIME_STAMP), join(target, RUNTIME_STAMP));

	const same = previous.version === next.version;
	console.log(
		same
			? `Atoms Cloudflare runtime in ${target} is already ${next.version}; runtime-owned files verified.`
			: `Atoms Cloudflare runtime in ${target}: ${previous.version} -> ${next.version}`,
	);
	const line = (label, paths) => {
		if (paths.length > 0) console.log(`  ${label}: ${paths.join(', ')}`);
	};
	line('updated', report.updated);
	line('added', report.added);
	line('removed (no longer shipped)', report.removed);
	line('seeded (user-owned, was absent)', report.seeded);
	line('kept (user-owned, yours)', report.kept);
	console.log(`  unchanged: ${report.unchanged.length} runtime-owned file(s)`);
	for (const path of report.modified) {
		console.log(`  note: ${path} had local changes and is runtime-owned; it was overwritten (your version is in git history).`);
	}
	for (const path of report.reowned) {
		console.log(`  note: ${path} is runtime-owned as of ${next.version} and was overwritten; your previous copy is in git history.`);
	}

	// 5. The one user-owned file the runtime depends on: say what it needs,
	//    never change it.
	const problems = checkWranglerConfig(
		readFileSync(join(target, 'wrangler.jsonc'), 'utf8'),
		readFileSync(template.get('wrangler.jsonc'), 'utf8'),
	);
	if (problems.length > 0) {
		console.log('');
		console.log('ACTION REQUIRED: wrangler.jsonc is yours and was not changed, but this release needs:');
		for (const problem of problems) console.log(`  - ${problem}`);
		console.log('Edit wrangler.jsonc accordingly, then run `npm ci` and commit.');
		return 1;
	}

	console.log(`Next: cd ${targetArg} && npm ci, review the diff, and commit.`);
	return 0;
}

// ---------------------------------------------------------------------------
// wrangler.jsonc: what the runtime requires from the file the user owns
// ---------------------------------------------------------------------------

/**
 * JSON with comments and trailing commas, which is what Wrangler reads. The
 * scanner is string-aware — a `//` or `,}` inside a string value is data —
 * because a stripper that is not is exactly how a config gets quietly
 * rewritten while parsing cleanly. It is not JSON5: no unquoted keys, no
 * single quotes, nothing Wrangler would not accept either.
 */
export function parseJsonc(text) {
	// Pass 1: drop comments. Pass 2: drop trailing commas. Two passes rather
	// than one so a comma followed by a comment and then `}` is still seen as
	// trailing; both passes copy string literals through untouched.
	const stripped = scan(text, (source, i) => {
		if (source[i] === '/' && source[i + 1] === '/') {
			let j = i;
			while (j < source.length && source[j] !== '\n') j++;
			return ['', j];
		}
		if (source[i] === '/' && source[i + 1] === '*') {
			const end = source.indexOf('*/', i + 2);
			if (end === -1) throw new SyntaxError('unterminated block comment');
			return ['', end + 2];
		}
		return null;
	});
	const trimmed = scan(stripped, (source, i) => {
		if (source[i] !== ',') return null;
		let j = i + 1;
		while (j < source.length && /\s/.test(source[j])) j++;
		return source[j] === '}' || source[j] === ']' ? ['', i + 1] : null;
	});
	return JSON.parse(trimmed);
}

/**
 * Copy `text`, letting `outside` rewrite anything that is not inside a string
 * literal. `outside(text, i)` returns `[replacement, nextIndex]` or null.
 */
function scan(text, outside) {
	let out = '';
	let i = 0;
	while (i < text.length) {
		if (text[i] === '"') {
			let j = i + 1;
			while (j < text.length && text[j] !== '"') {
				if (text[j] === '\\') j++;
				j++;
			}
			out += text.slice(i, j + 1);
			i = j + 1;
			continue;
		}
		const handled = outside(text, i);
		if (handled !== null) {
			out += handled[0];
			i = handled[1];
			continue;
		}
		out += text[i];
		i++;
	}
	return out;
}

function canonical(value) {
	if (Array.isArray(value)) return `[${value.map(canonical).join(',')}]`;
	if (value && typeof value === 'object') {
		return `{${Object.keys(value).sort().map((k) => `${JSON.stringify(k)}:${canonical(value[k])}`).join(',')}}`;
	}
	return JSON.stringify(value);
}

/**
 * The runtime's structural requirements on wrangler.jsonc, derived from the
 * template's copy so there is exactly one place that states them: the entry
 * point, the compatibility date as a floor, every compatibility flag, every
 * module rule, the Durable Object binding, and every migration tag. Returns
 * a list of plain-language problems; empty means the file will load this
 * release.
 */
export function checkWranglerConfig(userText, templateText) {
	const template = parseJsonc(templateText);
	let user;
	try {
		user = parseJsonc(userText);
	} catch (error) {
		return [`wrangler.jsonc could not be parsed as JSON with comments: ${error.message}`];
	}
	if (!user || typeof user !== 'object') return ['wrangler.jsonc must be a JSON object'];

	const problems = [];
	if (user.main !== template.main) {
		problems.push(`"main" must be ${JSON.stringify(template.main)} (found ${JSON.stringify(user.main ?? null)})`);
	}
	if (typeof user.compatibility_date !== 'string' || user.compatibility_date < template.compatibility_date) {
		problems.push(`"compatibility_date" must be ${template.compatibility_date} or later (found ${JSON.stringify(user.compatibility_date ?? null)})`);
	}
	const flags = Array.isArray(user.compatibility_flags) ? user.compatibility_flags : [];
	for (const flag of template.compatibility_flags ?? []) {
		if (!flags.includes(flag)) problems.push(`"compatibility_flags" must include ${JSON.stringify(flag)}`);
	}
	const rules = Array.isArray(user.rules) ? user.rules.map(canonical) : [];
	for (const rule of template.rules ?? []) {
		if (!rules.includes(canonical(rule))) problems.push(`"rules" must include ${JSON.stringify(rule)}`);
	}
	const bindings = Array.isArray(user.durable_objects?.bindings) ? user.durable_objects.bindings : [];
	for (const binding of template.durable_objects?.bindings ?? []) {
		const found = bindings.some((b) => b?.name === binding.name && b?.class_name === binding.class_name);
		if (!found) {
			problems.push(`"durable_objects.bindings" must include { "name": ${JSON.stringify(binding.name)}, "class_name": ${JSON.stringify(binding.class_name)} }`);
		}
	}
	const tags = Array.isArray(user.migrations) ? user.migrations.map((m) => m?.tag) : [];
	for (const migration of template.migrations ?? []) {
		if (!tags.includes(migration.tag)) problems.push(`"migrations" must include the tag ${JSON.stringify(migration.tag)}: ${JSON.stringify(migration)}`);
	}
	return problems;
}

// ---------------------------------------------------------------------------

/**
 * Run only as the entry point. npm invokes the bin through a symlink in
 * node_modules/.bin, so compare real paths; the test suite imports the
 * helpers above and must not trip the dispatch.
 */
function isMain() {
	try {
		return Boolean(process.argv[1]) && realpathSync(process.argv[1]) === fileURLToPath(import.meta.url);
	} catch {
		return false;
	}
}

if (isMain()) {
	const [command, target, ...extra] = process.argv.slice(2);
	if (!['init', 'upgrade'].includes(command) || !target || extra.length !== 0) {
		usage();
		process.exit(2);
	}

	try {
		if (command === 'init') scaffold(target);
		else process.exit(upgrade(target));
	} catch (error) {
		console.error(error instanceof Error ? error.message : String(error));
		process.exit(1);
	}
}
