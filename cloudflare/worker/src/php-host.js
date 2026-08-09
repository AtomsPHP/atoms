// This file is where the GPL enters. It is Atoms' own work, MIT like the rest
// of this tree, but it imports `@php-wasm/universal` and boots the
// Playground-derived runtime staged into `../.php-wasm/` — both
// GPL-2.0-or-later. Taken on its own this file is MIT; the Worker it helps
// assemble is a combined work under the GPL. See ../LICENSE.

/**
 * PHP-in-workerd host shim.
 *
 * Ported from `spikes/do-php/phase2-do/src/php-host.js` (proven mechanism), with
 * the JSPI engine dropped: JSPI cannot express either door (see below), and the
 * MVP ships Asyncify only.
 *
 * php-wasm ships exactly one PHP->JS door: the PHP function
 * `post_message_to_js(string): string`, implemented by the wasm import
 * `env.__asyncjs__js_module_onMessage`. Its stock implementation is
 *
 *     Asyncify.handleAsync(async () => Module.onMessage(str).then(writeResult))
 *
 * i.e. it ALWAYS goes through a promise, so it always pays a full Asyncify
 * unwind + rewind of the PHP stack even when JS has the answer immediately.
 * `ctx.storage.sql.exec()` is synchronous, so that is pure waste for the SQL
 * bridge, and — more importantly — a promise-resumed guest can never run inside
 * a synchronous `ctx.storage.transactionSync()` callback.
 *
 * We therefore replace that one import with a dispatcher keyed on the first
 * byte of the message:
 *
 *   '!'  SYNC — call a synchronous handler, write the reply into guest memory,
 *               return the length. No Asyncify involvement at all.
 *   '~'  PARK — call `Asyncify.handleSleep` ourselves so that WE own the
 *               `wakeUp` callback and can decide from which JS stack frame the
 *               guest is resumed. This is what the turn loop and the
 *               transaction seam need.
 *   else      — delegate to the stock implementation (php.onMessage listeners).
 *
 * NOTE ON JSPI: in the JSPI build `Asyncify.instrumentWasmImports()` wraps every
 * `__asyncjs__*` import in `new WebAssembly.Suspending(...)` BEFORE our
 * `instantiateWasm` hook sees the import object. A Suspending import always
 * suspends and is always resumed from a promise reaction, so neither '!' nor a
 * synchronous '~' resume is expressible there. In the Asyncify build
 * `instrumentWasmImports` is a no-op (its loop body is dead code), so the import
 * object arrives unwrapped and both work.
 */
import { loadPHPRuntime, PHP, __private__dont__use } from '@php-wasm/universal';

// Staged out of node_modules by scripts/prepare-runtime.mjs, which verifies
// the artifact's hash and applies the one-line Asyncify patch. `.php-wasm/` is
// generated and gitignored — the GPL interpreter is fetched from Playground's
// own npm package rather than redistributed by this repository.
import * as asyncifyLoader from '../.php-wasm/php_8_3.js';
import asyncifyWasm from '../.php-wasm/8_3_32/php_8_3.wasm';

import { AtomsError } from './errors.js';

const encoder = new TextEncoder();

const TAG_SYNC = 33; // '!'
const TAG_PARK = 126; // '~'

/**
 * Copy a JS string into guest memory and publish the pointer, Emscripten-style.
 *
 * The `HEAPU8` re-read after `_malloc` is load-bearing: allocation may grow the
 * wasm memory, which detaches every previously captured typed-array view.
 *
 * @param {any} M Emscripten Module
 * @param {number} responseBufferPtr
 * @param {string} str
 * @returns {number} byte length of the reply
 */
function writeReply(M, responseBufferPtr, str) {
	const bytes = encoder.encode(str);
	const ptr = M._malloc(bytes.length + 1);
	const h = M.HEAPU8; // re-read AFTER malloc: growth detaches the old view
	h.set(bytes, ptr);
	h[ptr + bytes.length] = 0;
	h[responseBufferPtr] = ptr & 0xff;
	h[responseBufferPtr + 1] = (ptr >>> 8) & 0xff;
	h[responseBufferPtr + 2] = (ptr >>> 16) & 0xff;
	h[responseBufferPtr + 3] = (ptr >>> 24) & 0xff;
	return bytes.length;
}

/**
 * @typedef {object} PhpDoors
 * @property {(msg: string) => string} onSync
 *   Handler for '!' messages. Receives the message without its tag byte and
 *   returns the JSON reply. Must not throw: a throw here unwinds through wasm.
 * @property {(msg: string, reply: (s: string) => void) => void} onPark
 *   Handler for '~' messages. Receives the message without its tag byte plus a
 *   `reply` callback that resumes the guest synchronously from whatever JS
 *   stack frame calls it.
 */

/**
 * @typedef {object} BootedPHP
 * @property {any} php        the `@php-wasm/universal` PHP instance
 * @property {any} module     the underlying Emscripten Module
 * @property {number} syncCalls
 * @property {number} parkCalls
 */

/**
 * Boot one PHP instance with the tagged doors installed.
 *
 * @param {PhpDoors} doors
 * @returns {Promise<any>} the PHP instance (with `__atoms` bookkeeping attached)
 */
export async function bootPHP({ onSync, onPark }) {
	if (typeof onSync !== 'function' || typeof onPark !== 'function') {
		throw new AtomsError('internal', 'bootPHP requires both onSync and onPark handlers');
	}

	const box = {
		/** @type {any} */ M: null,
		onSync,
		onPark,
		syncCalls: 0,
		parkCalls: 0,
	};

	const runtimeId = await loadPHPRuntime(asyncifyLoader, {
		/**
		 * Hand Wrangler's precompiled wasm module to Emscripten (workerd forbids
		 * compiling bytes at runtime) and swap the one import we care about.
		 *
		 * @param {any} imports
		 * @param {(instance: WebAssembly.Instance, module: WebAssembly.Module) => void} successCallback
		 */
		instantiateWasm(imports, successCallback) {
			const stock = imports.env.__asyncjs__js_module_onMessage;
			if (typeof stock !== 'function') {
				// Only happens on a JSPI-instrumented build, where the import has
				// already been wrapped in WebAssembly.Suspending.
				throw new AtomsError(
					'internal',
					'atoms: __asyncjs__js_module_onMessage is not a plain function; ' +
						'the Asyncify build is required for the Atoms host doors'
				);
			}
			imports.env.__asyncjs__js_module_onMessage = (dataPtr, respPtr) => {
				const M = box.M;
				if (M) {
					const msg = M.UTF8ToString(dataPtr);
					const tag = msg.charCodeAt(0);
					if (tag === TAG_SYNC) {
						box.syncCalls++;
						return writeReply(M, respPtr, box.onSync(msg.slice(1)));
					}
					if (tag === TAG_PARK) {
						box.parkCalls++;
						return M.Asyncify.handleSleep((wakeUp) => {
							box.onPark(msg.slice(1), (reply) => {
								wakeUp(writeReply(M, respPtr, reply));
							});
						});
					}
				}
				return stock(dataPtr, respPtr);
			};

			const instance = new WebAssembly.Instance(asyncifyWasm, imports);
			successCallback(instance, asyncifyWasm);
			return instance.exports;
		},
		locateFile: (/** @type {string} */ p) => p,
	});

	const php = new PHP(runtimeId);
	box.M = php[__private__dont__use];

	if (typeof box.M?.Asyncify?.handleSleep !== 'function') {
		throw new AtomsError(
			'internal',
			'atoms: Module.Asyncify.handleSleep is missing; the vendored php-wasm glue ' +
				"must carry the `Module['Asyncify'] = Asyncify;` patch"
		);
	}

	php.__atoms = box;
	php.__module = box.M;
	return php;
}

/**
 * Guest memory currently owned by the PHP instance, in bytes.
 *
 * Reported as the residency's memory high-water mark: Emscripten never shrinks
 * the wasm memory, so `HEAPU8.length` is exactly that.
 *
 * @param {any} php
 * @returns {number|null}
 */
export function guestMemoryBytes(php) {
	const h = php?.__module?.HEAPU8;
	return h ? h.length : null;
}

/**
 * Compose the bootstrap script: inject the boot payload as `$CFG` and hand
 * control to the runtime prelude (`php/README.md` §2, "Run exactly one composed
 * script"). `$ATOMS_BOOT` is bound to the same array so either name works for a
 * prelude that expects the other.
 *
 * The payload is a single JSON line inside a nowdoc, so nothing in it can
 * terminate the heredoc or be interpolated. `require`, never concatenate: every
 * verbatim atoms/core file opens with `declare(strict_types=1)`, which must be
 * the first statement of its own file (phase2-do/FINDINGS.md §7).
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
