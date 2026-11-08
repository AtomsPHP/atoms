/**
 * TypePHP's WASI command runs once per residency. Standard streams carry the
 * tagged host protocol. Jco's JSPI imports suspend reads until a reply arrives.
 * Each instance has its own streams and filesystem capabilities. It does not
 * use preview2-shim's shared compatibility state.
 */
import { createCli } from '@bytecodealliance/preview2-shim/cli';
import { createFilesystem, InMemoryFilesystemAdapter } from '@bytecodealliance/preview2-shim/filesystem';
import { pollableCreate } from '@bytecodealliance/preview2-shim/io';
import { WASIShim } from '@bytecodealliance/preview2-shim/instantiation';
import { AtomsError } from './errors.js';

const encoder = new TextEncoder();
const FRAME_PREFIX = encoder.encode('\x1eATOMS:');

function invalid(message) {
	return new AtomsError('internal', `TypePHP host: ${message}`);
}

/**
 * Check the declared frame size before allocating its body. Forward ordinary
 * output to the logger without retaining it in a buffer.
 */
class FrameReader {
	constructor(maxBytes, dispatch, log) {
		this.maxBytes = maxBytes;
		this.dispatch = dispatch;
		this.log = log;
		this.prefix = 0;
		this.header = null;
		this.body = null;
		this.offset = 0;
	}

	write(bytes) {
		let start = 0;
		while (start < bytes.length) {
			if (this.body !== null) {
				const count = Math.min(bytes.length - start, this.body.length - this.offset);
				this.body.set(bytes.subarray(start, start + count), this.offset);
				this.offset += count;
				start += count;
				if (this.offset === this.body.length) {
					const body = this.body;
					this.body = null;
					this.offset = 0;
					let message;
					try {
						message = new TextDecoder('utf-8', { fatal: true }).decode(body);
					} catch {
						throw invalid('bridge frame is not valid UTF-8');
					}
					this.dispatch(message);
				}
				continue;
			}

			if (this.header !== null) {
				const byte = bytes[start++];
				if (byte === 10) {
					if (!/^[1-9][0-9]*$/.test(this.header)) throw invalid('invalid bridge frame length');
					const length = Number(this.header);
					if (!Number.isSafeInteger(length) || length > this.maxBytes) throw invalid('bridge frame exceeds its byte limit');
					this.body = new Uint8Array(length);
					this.header = null;
				} else {
					if (byte < 48 || byte > 57 || this.header.length >= String(this.maxBytes).length) {
						throw invalid('invalid or oversized bridge frame length');
					}
					this.header += String.fromCharCode(byte);
				}
				continue;
			}

			if (bytes[start] === FRAME_PREFIX[this.prefix]) {
				start++;
				if (++this.prefix === FRAME_PREFIX.length) {
					this.prefix = 0;
					this.header = '';
				}
			} else if (this.prefix > 0) {
				this.log(FRAME_PREFIX.subarray(0, this.prefix));
				this.prefix = 0; // reconsider this byte as a possible new prefix
			} else {
				const end = bytes.indexOf(FRAME_PREFIX[0], start);
				const next = end === -1 ? bytes.length : end;
				this.log(bytes.subarray(start, next));
				start = next;
			}
		}
	}

	finish() {
		if (this.body !== null || this.header !== null) throw invalid('guest exited with an incomplete bridge frame');
		if (this.prefix > 0) this.log(FRAME_PREFIX.subarray(0, this.prefix));
		this.prefix = 0;
	}
}

/**
 * @param {object} options
 * @param {Function} options.instantiate Jco's generated instantiate function
 * @param {Record<string, WebAssembly.Module>} options.coreModules Wrangler imports keyed by Jco filename
 * @param {(message: string) => string} options.onSync
 * @param {(message: string, reply: (message: string) => void) => void} options.onPark
 * @param {(bytes: Uint8Array) => void} [options.onLog]
 * @param {number} options.maxFrameBytes Resolved in config.js; includes boot/reply frames
 */
export function bootTypePhp({ instantiate, coreModules, onSync, onPark, onLog = () => {}, maxFrameBytes }) {
	if (typeof instantiate !== 'function' || typeof onSync !== 'function' || typeof onPark !== 'function') {
		throw invalid('instantiate, onSync and onPark handlers are required');
	}
	if (coreModules === null || typeof coreModules !== 'object') throw invalid('precompiled core modules are required');
	if (!Number.isSafeInteger(maxFrameBytes) || maxFrameBytes <= 0) throw invalid('a positive bridge byte limit is required');

	const tree = { dir: Object.create(null) };
	const memories = new Set();
	const counters = { syncCalls: 0, parkCalls: 0 };
	let started = false;
	let stopped = false;
	let failure = null;
	let input = new Uint8Array();
	let inputOffset = 0;
	let wake;
	let available;
	const resetWait = () => { available = new Promise(resolve => { wake = resolve; }); };
	resetWait();

	function fail(error) {
		failure ??= error;
		stopped = true;
		wake();
	}

	function log(bytes) {
		// A logger failure must not interrupt guest execution.
		try { onLog(bytes); } catch { /* Logging is best effort. */ }
	}

	function pushReply(reply) {
		if (stopped) return;
		try {
			if (typeof reply !== 'string' || /[\r\n]/.test(reply)) throw invalid('host reply must be a single line');
			const bytes = encoder.encode(reply);
			if (bytes.length > maxFrameBytes) throw invalid('host reply exceeds its byte limit');
			if (inputOffset < input.length) throw invalid('host replied before the previous reply was consumed');
			input = new Uint8Array(bytes.length + 1);
			input.set(bytes);
			input[bytes.length] = 10;
			inputOffset = 0;
			wake();
		} catch (error) {
			// A park reply can arrive outside the guest's stack. Wake its pending
			// read with the failure instead of leaving php.run() unresolved.
			fail(error);
		}
	}

	function read(length) {
		if (failure !== null) throw failure;
		if (stopped) throw { tag: 'closed' };
		if (input.length === 0 || length === 0n) return new Uint8Array();
		const count = Math.min(Number(length), input.length - inputOffset);
		const result = input.slice(inputOffset, inputOffset + count);
		inputOffset += count;
		if (inputOffset === input.length) {
			input = new Uint8Array();
			inputOffset = 0;
			resetWait();
		}
		return result;
	}
	const stdin = {
		read,
		blockingRead(length) {
			if (inputOffset < input.length || stopped || length === 0n) return read(length);
			return available.then(() => stdin.blockingRead(length));
		},
		subscribe() {
			return pollableCreate({ ready: () => stopped || inputOffset < input.length, wait: () => available });
		},
	};

	const frames = new FrameReader(maxFrameBytes, message => {
		if (stopped) throw failure ?? invalid('guest output after shutdown');
		if (message[0] === '!') {
			counters.syncCalls++;
			pushReply(onSync(message.slice(1)));
		} else if (message[0] === '~') {
			counters.parkCalls++;
			let replied = false;
			onPark(message.slice(1), reply => {
				if (replied || stopped) return;
				replied = true;
				pushReply(reply);
			});
		} else {
			throw invalid('unrecognized bridge door');
		}
	}, log);

	function locate(path, makeParents = false) {
		if (typeof path !== 'string' || !path.startsWith('/') || path.includes('\0')) throw invalid('guest paths must be absolute');
		const segments = path.split('/').filter(Boolean);
		if (segments.some(segment => segment === '.' || segment === '..')) throw invalid('guest paths must be normalized');
		const name = segments.pop();
		let parent = tree;
		for (const segment of segments) {
			if (makeParents) parent.dir[segment] ??= { dir: Object.create(null) };
			parent = parent.dir[segment];
			if (!parent?.dir) return null;
		}
		return { parent, name };
	}

	return {
		__atoms: counters,
		// The default host reads HEAPU8. This backend exposes its imported
		// memories directly so the common memory helper can sum their sizes.
		get memoryBytes() {
			return memories.size ? [...memories].reduce((sum, memory) => sum + memory.buffer.byteLength, 0) : null;
		},
		mkdir(path) {
			const target = locate(path, true);
			if (!target) throw invalid(`cannot create guest directory ${path}`);
			if (target.name) {
				target.parent.dir[target.name] ??= { dir: Object.create(null) };
				if (!target.parent.dir[target.name].dir) throw invalid(`guest path is not a directory: ${path}`);
			}
		},
		writeFile(path, data) {
			const target = locate(path, true);
			if (!target?.name) throw invalid(`cannot write guest file ${path}`);
			if (target.parent.dir[target.name]?.dir) throw invalid(`guest path is a directory: ${path}`);
			target.parent.dir[target.name] = { source: typeof data === 'string' ? encoder.encode(data) : new Uint8Array(data) };
		},
		fileExists(path) {
			if (path === '/') return true;
			const target = locate(path);
			return !!(target && Object.hasOwn(target.parent.dir, target.name));
		},
		async run({ bootPayload }) {
			if (started || stopped) throw invalid('a residency can only be run once');
			started = true;
			let filesystem;
			let cli;
			try {
				if (bootPayload === undefined) throw invalid('boot payload is required');
				pushReply(JSON.stringify(bootPayload));
				if (failure !== null) throw failure;
				cli = createCli({
					stdin,
					stdout: { write: bytes => frames.write(bytes), blockingFlush() {} },
					stderr: { write: log, blockingFlush() {} },
					arguments: ['atoms'], environment: {}, initialCwd: '/',
				});
				filesystem = createFilesystem({ adapter: new InMemoryFilesystemAdapter(), preopens: { '/': tree } });
				const wasi = new WASIShim({ cli, filesystem, sandbox: { enableNetwork: false } });
				const component = await instantiate(name => {
					if (!Object.hasOwn(coreModules, name) || !(coreModules[name] instanceof WebAssembly.Module)) {
						throw invalid(`missing precompiled core module ${name}`);
					}
					return coreModules[name];
				}, wasi.getImportObject(), (module, imports) => {
					const instance = new WebAssembly.Instance(module, imports);
					for (const value of Object.values(instance.exports)) {
						if (value instanceof WebAssembly.Memory) memories.add(value);
					}
					return instance;
				});
				if (stopped) throw failure ?? invalid('residency stopped during instantiation');
				await component.run.run();
				if (failure !== null) throw failure;
				frames.finish();
			} finally {
				stopped = true;
				input = new Uint8Array();
				wake();
				// Dropping the stdin resource also invalidates its pollables.
				cli?.stdin.getStdin()[Symbol.dispose]();
				cli?.stdout.getStdout()[Symbol.dispose]();
				cli?.stderr.getStderr()[Symbol.dispose]();
				await filesystem?.dispose();
			}
		},
		exit() {
			stopped = true;
			input = new Uint8Array();
			wake();
		},
	};
}

/** @param {{memoryBytes?: number|null}|null} php */
export function guestMemoryBytes(php) {
	return php?.memoryBytes ?? null;
}
