import { AtomsError } from './errors.js';

/**
 * Compose the bootstrap script with the boot payload in `$CFG`, then load the
 * guest runtime. See `php/README.md` §2, "Run exactly one composed script".
 * Assign the same array to `$ATOMS_BOOT` for guest code that uses that name.
 *
 * The payload is a single JSON line inside a nowdoc, so nothing in it can
 * terminate the heredoc or be interpolated. Load files with `require` so each
 * atoms/core file keeps `declare(strict_types=1)` as its first statement.
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
 * Create the parent directories and write a file into the guest filesystem.
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
		// php-wasm uses mkdirTree and accepts existing directories. If mkdir
		// throws, check whether the requested path exists before failing.
		if (!php.fileExists(dir)) {
			throw new AtomsError('internal', `cannot create guest directory ${dir}`, { cause: e });
		}
	}
}
