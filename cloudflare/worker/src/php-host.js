import { bootPHP as bootEmbed } from './embed-loader.js';
import { AtomsError } from './errors.js';
export { composeBootCode, writeGuestFile, mkdirp } from './guest-files.js';

// The staged entry supplies a matched factory and Wrangler-compiled Wasm module.
export function bootPHP({ factory, wasm }, { onSync, onPark, print = console.log, printErr = console.error }) {
	if (typeof onSync !== 'function' || typeof onPark !== 'function') {
		throw new AtomsError('internal', 'bootPHP requires both onSync and onPark handlers');
	}
	return bootEmbed(factory, wasm, { onSync, onPark, print, printErr });
}

export function guestMemoryBytes(php) {
	return php?.memoryBytes ?? null;
}
