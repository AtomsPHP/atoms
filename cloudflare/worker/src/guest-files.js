import { AtomsError } from './errors.js';

/**
 * Compose the bootstrap script: inject the boot payload as `$CFG` and hand
 * control to the guest runtime (`php/README.md` §2, "Run exactly one composed
 * script"). `$ATOMS_BOOT` is bound to the same array so either name works for
 * guest code that expects the other.
 *
 * The payload is a single JSON line inside a nowdoc, so nothing in it can
 * terminate the heredoc or be interpolated. `require`, never concatenate: every
 * verbatim atoms/core file opens with `declare(strict_types=1)`, which must be
 * the first statement of its own file.
 *
 * @param {unknown} payload
 * @param {string} bootstrapPath guest path of the runtime bootstrap script
 * @returns {string}
 */
export function composeBootCode(payload, bootstrapPath) {
	const json = JSON.stringify(payload);
	if (json.includes('\n')) {
		throw new AtomsError('internal', 'boot payload JSON must be a single line');
	}
	return (
		"<?php\n$CFG = json_decode(<<<'ATOMSBOOTJSON'\n" +
		json +
		"\nATOMSBOOTJSON, true);\n" +
		'$ATOMS_BOOT = $CFG;\n' +
		`require ${JSON.stringify(bootstrapPath)};\n`
	);
}

/**
 * Write one file into the guest MEMFS, creating parent directories.
 *
 * @param {any} php
 * @param {string} path absolute guest path
 * @param {string|Uint8Array} contents
 */
export function writeGuestFile(php, path, contents) {
	const dir = path.slice(0, path.lastIndexOf('/'));
	if (dir) mkdirp(php, dir);
	php.writeFile(path, contents);
}

/**
 * @param {any} php
 * @param {string} dir
 */
export function mkdirp(php, dir) {
	try {
		php.mkdir(dir);
	} catch (e) {
		// php-wasm's mkdir is mkdirTree-backed and idempotent in practice; an
		// EEXIST here is fine, anything else is a real failure.
		if (!php.fileExists(dir)) {
			throw new AtomsError('internal', `cannot create guest directory ${dir}`, { cause: e });
		}
	}
}
