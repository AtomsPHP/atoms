#!/usr/bin/env node

/**
 * bundle-from-cli.mjs — turn an `atoms build` bundle into a deployable Worker.
 *
 * This is the seam between the two halves of the repository, and it exists
 * because they are deliberately not the same artifact:
 *
 *   `atoms build` emits `bundle-{sha256}.tar.gz` + `manifest.json`. That is the
 *   PORTABLE artifact: content-addressed, byte-reproducible, signable,
 *   archivable, and produced without executing customer code. It carries the
 *   customer's app and nothing else.
 *
 *   The Worker loads `src/bundle.generated.js`, an ES module exporting
 *   `{manifest, files}`. That is the DEPLOY artifact: it has to be a JS module
 *   because the Worker script is what `wrangler deploy` uploads, and it has to
 *   carry the `Atoms\Cf` runtime prelude and the vendored `atoms/core` sources
 *   as well — neither of which `atoms build` has any business knowing about.
 *
 * Neither format is wrong, and neither should grow into the other. What was
 * missing was the translation, which `build-bundle.mjs` said in its own header
 * it was standing in for. This is that translation; `build-bundle.mjs` keeps
 * its narrower, still-real job of building the conformance fixture.
 *
 * Nothing under `src/` or `php/` changes as a result: the module emitted here
 * is the same shape the host already reads, so the conformance suite is
 * untouched by the CLI integration.
 *
 * Usage:
 *   node bundle-from-cli.mjs <bundle.tar.gz> <manifest.json> [output-file]
 *
 * Default output: src/bundle.generated.js
 */

import fs from 'node:fs';
import path from 'node:path';
import zlib from 'node:zlib';
import crypto from 'node:crypto';
import { fileURLToPath } from 'node:url';

import { runtimeFiles, sortedFiles, writeBundleModule } from './lib/bundle-module.mjs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const PROJECT_ROOT = path.resolve(__dirname, '..');
const SUPPORTED_CORE_PATH = path.join(PROJECT_ROOT, 'release', 'supported-core');

/** Guest prefix for the customer's own bundle files. */
const APP_PREFIX = '/app';

/**
 * The bundle format version the host understands. Unchanged: this script emits
 * exactly what `src/index.js` and `php/runtime/bootstrap.php` already consume.
 */
const BUNDLE_FORMAT = 0;

/**
 * Test an exact semantic version against the generated caret range stamped
 * into this runtime. M7 ships ^0.1; accepting the general caret form here
 * keeps the check correct when a later release tool advances that stamp.
 */
function supportsCore(version, range) {
	const actual = /^(\d+)\.(\d+)\.(\d+)(?:-[0-9A-Za-z.-]+)?$/.exec(version);
	const supported = /^\^(\d+)\.(\d+)(?:\.(\d+))?$/.exec(range);
	if (!actual || !supported) return false;

	const [major, minor, patch] = actual.slice(1, 4).map(Number);
	const supportedMajor = Number(supported[1]);
	const supportedMinor = Number(supported[2]);
	const supportedPatch = Number(supported[3] ?? 0);

	if (major !== supportedMajor) return false;
	if (supportedMajor === 0) {
		return minor === supportedMinor && patch >= supportedPatch;
	}
	return minor > supportedMinor || (minor === supportedMinor && patch >= supportedPatch);
}

function assertCoreVersion(cli) {
	const supported = fs.readFileSync(SUPPORTED_CORE_PATH, 'utf8').trim();
	const built = cli.toolchain?.core_version;
	if (typeof built !== 'string' || !supportsCore(built, supported)) {
		throw new Error(
			`ATOMS-E043: Bundle was built against atoms/core ${JSON.stringify(built ?? null)}, ` +
				`but this runtime supports ${supported}.`,
		);
	}
}

/**
 * Read a ustar archive into a Map of name => contents (utf-8).
 *
 * Written against `Atoms\Cli\Build\TarWriter`, which emits plain ustar with
 * mtime/uid/gid zeroed: 512-byte headers, regular files only, `prefix` used for
 * long names. No GNU long-name or PAX extensions are produced, so none are
 * accepted — an unrecognised type flag is an error rather than a silent skip.
 *
 * @param {Buffer} buf
 * @returns {Map<string, string>}
 */
function readTar(buf) {
	const BLOCK = 512;
	const files = new Map();
	let offset = 0;

	const str = (start, len) => {
		const raw = buf.subarray(start, start + len);
		const end = raw.indexOf(0);
		return raw.subarray(0, end === -1 ? raw.length : end).toString('utf-8');
	};

	while (offset + BLOCK <= buf.length) {
		// Two zero blocks terminate the archive; one is enough to stop on.
		if (buf.subarray(offset, offset + BLOCK).every((b) => b === 0)) break;

		const name = str(offset, 100);
		const sizeField = str(offset + 124, 12).trim();
		const typeFlag = String.fromCharCode(buf[offset + 156]);
		const prefix = str(offset + 345, 155);

		const size = parseInt(sizeField, 8);
		if (!Number.isFinite(size) || size < 0) {
			throw new Error(`tar entry ${name} has an unreadable size field ${JSON.stringify(sizeField)}`);
		}

		// '0' and '\0' both mean "regular file" in the wild.
		if (typeFlag !== '0' && typeFlag !== '\0') {
			throw new Error(`tar entry ${name} has unsupported type flag ${JSON.stringify(typeFlag)}`);
		}

		const start = offset + BLOCK;
		files.set(prefix ? `${prefix}/${name}` : name, buf.subarray(start, start + size).toString('utf-8'));

		offset = start + Math.ceil(size / BLOCK) * BLOCK;
	}

	return files;
}

/**
 * Project the CLI's schema-1 manifest onto the host manifest the Worker reads.
 *
 * The CLI manifest is the richer document — every method signature, jobs,
 * shared DTOs, toolchain fingerprint. The host needs a small, flat projection
 * of it, so this narrows rather than transforms: `atoms` becomes a map keyed by
 * wire type, and paths become guest paths. Provenance the host does not read
 * (`project`, `content_hash`) is carried through anyway, so a deployed Worker
 * can be traced back to the exact bundle that produced it.
 *
 * @param {any} cli
 * @returns {any}
 */
function hostManifest(cli) {
	if (cli.schema !== 1) {
		throw new Error(`manifest.json has schema ${JSON.stringify(cli.schema)}; this script understands schema 1`);
	}
	if (!Array.isArray(cli.atoms)) {
		throw new Error('manifest.json has no "atoms" list');
	}
	assertCoreVersion(cli);

	const atoms = {};
	for (const atom of cli.atoms) {
		if (typeof atom.type !== 'string' || atom.type === '') {
			throw new Error('an atoms[] entry has no "type"');
		}
		if (typeof atom.file !== 'string' || atom.file === '') {
			throw new Error(
				`atom ${atom.type} has no "file" in the manifest. ` +
					'It is emitted by `atoms build`; rebuild with a current CLI.'
			);
		}

		const migrations = (atom.migrations?.files ?? []).map((m) => {
			if (typeof m.path !== 'string' || m.path === '') {
				throw new Error(
					`migration ${m.version}_${m.name} of atom ${atom.type} has no "path" in the manifest. ` +
						'It is emitted by `atoms build`; rebuild with a current CLI.'
				);
			}
			return path.posix.join(APP_PREFIX, m.path);
		});

		atoms[atom.type] = {
			class: atom.class,
			file: path.posix.join(APP_PREFIX, atom.file),
			// Additive manifest field (docs/cloudflare-toolchain.md §3), carried
			// through as the TRI-STATE the CLI emits, never collapsed to a
			// definite bool. `ManifestGenerator::websocketFlag()` produces three
			// shapes and the runtime reads all three:
			//   - present `true`  => the Atom declares a WS handler        => allowed;
			//   - present `false` => it extends Atoms\Atom DIRECTLY and declares
			//                        none, a claim discovery could prove     => 501;
			//   - ABSENT          => it extends something discovery cannot follow,
			//                        so an inherited handler is unknowable to a
			//                        file-parser => allowed, runtime dispatch decides.
			// Collapsing the absent case to `false` here (the old
			// `websocket: atom.websocket === true`) re-manufactured the wrongful
			// 501 that the omission exists to avoid: index.js refuses
			// `websocket === false` before any Durable Object is touched. So the
			// key is spread only when the CLI actually emitted it — absence is
			// preserved as absence.
			...(atom.websocket === undefined ? {} : { websocket: atom.websocket === true }),
			migrations,
		};
	}

	return {
		bundle_format: BUNDLE_FORMAT,
		abi: { php: cli.toolchain?.php ?? '8.3' },
		atoms,
		// Additive manifest field (docs/cloudflare-toolchain.md §3): the
		// bundled vendor autoloader, guest-pathed like atoms[].file. Spread
		// like `websocket` above — absence stays absence (a bundle with no
		// atoms-composer.json packages declares nothing).
		...(typeof cli.vendor?.autoload === 'string' && cli.vendor.autoload !== ''
			? { vendor: { autoload: path.posix.join(APP_PREFIX, cli.vendor.autoload) } }
			: {}),
		// Provenance, for `atoms status` and for reading a deployed Worker.
		project: cli.project ?? null,
		content_hash: cli.content_hash,
	};
}

function main() {
	const [bundleArg, manifestArg, outputArg] = process.argv.slice(2);
	if (!bundleArg || !manifestArg) {
		console.error('Usage: node bundle-from-cli.mjs <bundle.tar.gz> <manifest.json> [output-file]');
		process.exit(2);
	}

	const bundlePath = path.resolve(bundleArg);
	const manifestPath = path.resolve(manifestArg);
	const outputFile = path.resolve(PROJECT_ROOT, outputArg || 'src/bundle.generated.js');

	console.error(`Reading bundle:   ${bundlePath}`);
	console.error(`Reading manifest: ${manifestPath}`);
	console.error(`Writing bundle to: ${outputFile}`);

	const cli = JSON.parse(fs.readFileSync(manifestPath, 'utf-8'));
	const manifest = hostManifest(cli);

	const tar = zlib.gunzipSync(fs.readFileSync(bundlePath));

	// The manifest's content_hash is the sha256 of the UNCOMPRESSED tar, which
	// is how `Atoms\Cli\Build\BundleWriter` computes it. Recompute it here
	// rather than trusting the filename: a filename comparison only catches a
	// mispaired archive, and renaming an altered archive to the expected name
	// defeats it entirely. This is the check that makes "content-addressed"
	// mean something at the point the bundle is consumed.
	// Mandatory, never conditional. Guarding this on `if (cli.content_hash)`
	// meant deleting the field skipped verification altogether — the one check
	// that makes "content-addressed" mean anything at the point of consumption,
	// opted out of by removing a line. `BundleWriter` always emits it, so a
	// manifest without one did not come from `atoms build`, and the right
	// answer is to refuse rather than to trust the archive.
	if (typeof cli.content_hash !== 'string' || !/^[0-9a-f]{64}$/.test(cli.content_hash)) {
		throw new Error(
			`manifest.json has no usable content_hash (${JSON.stringify(cli.content_hash ?? null)}). ` +
				'`atoms build` always emits one; a manifest without it cannot be verified against its ' +
				'archive, and an unverified bundle is not deployable.'
		);
	}

	const actual = crypto.createHash('sha256').update(tar).digest('hex');
	if (actual !== cli.content_hash) {
		throw new Error(
			`bundle content hash ${actual} does not match the manifest's content_hash ` +
				`${cli.content_hash} — the manifest and the archive are not a matching pair`
		);
	}

	const appFiles = {};
	for (const [name, contents] of readTar(tar)) {
		appFiles[path.posix.join(APP_PREFIX, name)] = contents;
	}

	for (const [type, entry] of Object.entries(manifest.atoms)) {
		if (!(entry.file in appFiles)) {
			throw new Error(`atom ${type} declares ${entry.file}, which is not in the bundle`);
		}
		for (const migration of entry.migrations) {
			if (!(migration in appFiles)) {
				throw new Error(`atom ${type} declares migration ${migration}, which is not in the bundle`);
			}
		}
	}
	if (manifest.vendor && !(manifest.vendor.autoload in appFiles)) {
		throw new Error(`the manifest declares vendor autoload ${manifest.vendor.autoload}, which is not in the bundle`);
	}

	const all = { ...appFiles, ...runtimeFiles(PROJECT_ROOT) };
	const files = sortedFiles(all);

	writeBundleModule('bundle-from-cli.mjs', manifest, files, outputFile);

	console.error('Bundle generated successfully');
	console.error(`Files included: ${Object.keys(files).length}`);
	console.error(`Atoms defined:  ${Object.keys(manifest.atoms).length}`);
}

try {
	main();
} catch (err) {
	console.error(`Error: ${err.message}`);
	process.exit(1);
}
