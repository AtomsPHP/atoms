// Atoms-authored MIT source. It imports `@php-wasm/universal` and boots the
// WordPress Playground runtime staged into `../.php-wasm/`. See
// ../THIRD_PARTY_NOTICES.md for component licenses and provenance.

/**
 * Host adapter for php-wasm's Asyncify build in workerd.
 *
 * php-wasm exposes `post_message_to_js(string): string` through the import
 * `env.__asyncjs__js_module_onMessage`. Its default implementation uses
 *
 *     Asyncify.handleAsync(async () => Module.onMessage(str).then(writeResult))
 *
 * Every call suspends and resumes the PHP stack through a promise, even when
 * JavaScript has the reply immediately. That suspension is unnecessary for
 * synchronous SQL calls and prevents guest execution inside a synchronous
 * `ctx.storage.transactionSync()` callback.
 *
 * This adapter replaces the import with a dispatcher for the first message byte:
 *
 * - '!' calls the synchronous handler, writes the reply to guest memory, and
 *   returns its length without suspending through Asyncify.
 * - '~' calls `Asyncify.handleSleep`. The host owns the `wakeUp` callback and
 *   resumes the guest from the JavaScript stack frame that handles the reply.
 *   The turn loop and synchronous transactions require this control.
 * - Other messages use the original handler and its php.onMessage listeners.
 *
 * php-wasm's JSPI build wraps each `__asyncjs__*` import in
 * `WebAssembly.Suspending` before `instantiateWasm` receives it. Those imports
 * resume through promise reactions, so this adapter cannot use them for
 * synchronous calls or resumes. The Asyncify build leaves the imports unwrapped.
 */
import { loadPHPRuntime, PHP, __private__dont__use } from '@php-wasm/universal';

// Staged out of node_modules by scripts/prepare-runtime.mjs, which verifies
// the artifact's hash and applies the one-line Asyncify patch. `.php-wasm/` is
// generated and gitignored; the interpreter is fetched from Playground's npm
// package.
import * as asyncifyLoader from '../.php-wasm/php_8_3.js';
import asyncifyWasm from '../.php-wasm/8_3_32/php_8_3.wasm';

import { AtomsError } from './errors.js';

const encoder = new TextEncoder();

const TAG_SYNC = 33; // '!'
const TAG_PARK = 126; // '~'

/**
 * Copy a JavaScript string into guest memory and write its pointer at responseBufferPtr.
 *
 * Read `HEAPU8` again after `_malloc`. Allocation may grow WebAssembly memory
 * and detach previously captured typed-array views.
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
 *   returns the JSON reply. Throwing here unwinds through WebAssembly.
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
 * @returns {Promise<any>} the PHP instance with `__atoms` call counters
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
		 * Pass Wrangler's precompiled module to Emscripten and replace its
		 * message import. workerd forbids compiling WebAssembly bytes at runtime.
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
 * Emscripten never shrinks WebAssembly memory. `HEAPU8.length` therefore
 * reports the residency's maximum allocation.
 *
 * @param {any} php
 * @returns {number|null}
 */
export function guestMemoryBytes(php) {
	const h = php?.__module?.HEAPU8;
	return h ? h.length : null;
}
