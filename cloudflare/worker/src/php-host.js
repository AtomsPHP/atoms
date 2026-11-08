// The staging step selects one static backend, so Wrangler includes only
// that backend's WebAssembly modules.
export { bootPHP, guestMemoryBytes } from './runtime.generated.js';
export { composeBootCode, mkdirp, writeGuestFile } from './guest-files.js';
