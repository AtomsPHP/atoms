import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { spawnSync } from 'node:child_process';

const CACHE = '/tmp/atoms-typephp';
const FORMAT = 'atoms-typephp-v1';
const COMPONENT_HEADER = Buffer.from([0, 97, 115, 109, 13, 0, 1, 0]);
const LIBRARIES = ['php', 'phpx', 'gmp', 'gmpxx', 'mpfr', 'mpdec', 'mpdec++'];
const SYMBOLS = [
	'typephp_install_reflection_attribute_handlers()',
	'typephp_uninstall_reflection_attribute_handlers()',
	'php::FunctionCallCacheSlot::~FunctionCallCacheSlot()',
	'php::MethodCallCacheSlot::~MethodCallCacheSlot()',
	'typephp_runtime_start', 'typephp_runtime_stop',
	'typephp_read_property_cached(php::Variant const&, php::String const&, php::AttrMode, php::PropertyCacheSlot&)',
	'typephp_write_property_scoped(php::Variant const&, php::Variant const&, php::Variant const&, _zend_class_entry*)',
];

function run(command, args, { cwd, env }) {
	const toolEnv = Object.fromEntries(Object.entries(env).filter(([name]) => !/^(CLOUDFLARE_|CF_API_|ATOMS_SHARED_SECRET)/.test(name)));
	const result = spawnSync(command, args, { cwd, env: toolEnv, encoding: 'utf8', shell: false, stdio: ['ignore', 'pipe', 'pipe'] });
	if (result.error || result.status !== 0) {
		throw new Error(`TypePHP tool failed: ${command}: ${result.error?.message ?? `exit ${result.status}`}\n${result.stderr ?? ''}${result.stdout ?? ''}`.trim());
	}
	return `${result.stdout ?? ''}${result.stderr ?? ''}`;
}

function digest(value) {
	return crypto.createHash('sha256').update(value).digest('hex');
}

function regularFile(filename) {
	if (!fs.lstatSync(filename).isFile()) throw new Error(`TypePHP requires a regular file: ${filename}`);
	return fs.readFileSync(filename);
}

/** Never follow a symlink while creating or replacing a build directory. */
function directory(dir, privateDirectory = false) {
	const absolute = path.resolve(dir);
	let current = path.parse(absolute).root;
	for (const part of absolute.slice(current.length).split(path.sep).filter(Boolean)) {
		current = path.join(current, part);
		try { fs.mkdirSync(current, { mode: 0o700 }); } catch (error) { if (error.code !== 'EEXIST') throw error; }
		if (!fs.lstatSync(current).isDirectory()) throw new Error(`TypePHP directory is not a plain directory: ${current}`);
	}
	const stat = fs.lstatSync(absolute);
	if (privateDirectory && (stat.uid !== process.getuid() || (stat.mode & 0o077) !== 0)) {
		throw new Error(`TypePHP staging directory must be owned by the current user with mode 0700: ${absolute}`);
	}
	return absolute;
}

function headerTree(dir) {
	const entries = [];
	for (const name of fs.readdirSync(dir).sort()) {
		const file = path.join(dir, name);
		const stat = fs.lstatSync(file);
		if (stat.isDirectory()) entries.push([name, headerTree(file)]);
		else if (stat.isFile()) entries.push([name, digest(fs.readFileSync(file))]);
		else throw new Error(`TypePHP SDK contains a non-regular entry: ${file}`);
	}
	return digest(JSON.stringify(entries));
}

/** Checks installed tools only. It never installs or repairs a compiler or SDK. */
export function preflightTypePhp({ compiler = process.env.ATOMS_TYPEPHP_COMPILER || 'tpc', phpxHome = process.env.PHPX_HOME, env = process.env } = {}) {
	if (process.platform !== 'linux') throw new Error('The experimental TypePHP compiler currently requires Linux.');
	if (!phpxHome) throw new Error('Set PHPX_HOME to a locally installed PHPX 2.9.4 tree with its complete WASI SDK.');
	const environment = { ...env, PHPX_HOME: path.resolve(phpxHome), SOURCE_DATE_EPOCH: '0' };
	const options = { env: environment };
	const compilerVersion = run(compiler, ['--version'], options).trim();
	if (!/^TypePHP Compiler \(AOT\) v0\.9\.5$/.test(compilerVersion)) throw new Error(`TypePHP 0.9.5 is required; found ${compilerVersion}.`);
	for (const tool of ['wasm32-wasip2-clang', 'wasm32-wasip2-clang++', 'llvm-ar', 'llvm-ranlib', 'llvm-nm', 'wasm-component-ld']) {
		const version = run(tool, ['--version'], options);
		if (!/22\.1\.0/.test(version) && tool !== 'wasm-component-ld') throw new Error(`WASI SDK 33 (LLVM 22.1.0) is required: ${tool}.`);
		if (tool === 'wasm-component-ld' && version.trim() !== 'wasm-component-ld 0.5.22') throw new Error(`wasm-component-ld 0.5.22 is required; found ${version.trim()}.`);
	}
	const target = run('wasm32-wasip2-clang++', ['-dumpmachine'], options).trim();
	if (target !== 'wasm32-unknown-wasip2') throw new Error(`Expected wasm32-unknown-wasip2; found ${target}.`);
	const wasmtimeVersion = run('wasmtime', ['--version'], options).trim();
	if (!/^wasmtime 47\.0\.0\b/.test(wasmtimeVersion)) throw new Error(`Wasmtime 47.0.0 is required; found ${wasmtimeVersion}.`);
	const sdk = path.join(environment.PHPX_HOME, 'wasm/wasm32-wasip2');
	if (regularFile(path.join(sdk, '.typephp-wasi-sdk-abi')).toString().trim() !== 'typephp-wasip2-sdk-abi-v4') throw new Error(`Incompatible TypePHP WASI SDK: ${sdk}.`);
	for (const header of ['php/main/php.h', 'php/main/php_config.h', 'php/Zend/zend_config.h', 'php/TSRM/TSRM.h', 'phpx/phpx.h', 'phpx/phpx_exception_policy.h', 'phpx/phpx_cast_policy.h', 'phpx/typephp_runtime.h', 'phpx/typephp_opcode_table.h', 'phpx/typephp_helper.h']) {
		regularFile(path.join(sdk, 'include', header));
	}
	const libraries = Object.fromEntries(LIBRARIES.map((name) => [name, digest(regularFile(path.join(sdk, 'lib', `lib${name}.a`)))]));
	const symbols = run('llvm-nm', ['--defined-only', '--demangle', path.join(sdk, 'lib/libphpx.a')], options);
	const defined = new Set(symbols.split('\n').flatMap((line) => { const match = /^\s*[0-9a-f]+\s+[Tt]\s+(.+)$/.exec(line); return match ? [match[1]] : []; }));
	const missing = SYMBOLS.filter((symbol) => !defined.has(symbol));
	if (missing.length) throw new Error(`Incomplete TypePHP WASI SDK; install a corrected SDK containing: ${missing.join(', ')}. No SDK files were changed.`);
	return { environment, fingerprint: { compiler: compilerVersion, target, wasmtime: wasmtimeVersion, libraries, headers: headerTree(path.join(sdk, 'include')) } };
}

/** Retain every Jco WASM output, including conditional-host-import modules. */
export function transpileTypePhp({ componentPath, outputDir, jco = 'jco', env = process.env }) {
	const version = run(jco, ['--version'], { env }).trim();
	if (version !== '1.41.0') throw new Error(`Jco 1.41.0 is required; found ${version}.`);
	run(jco, ['transpile', componentPath, '-o', outputDir, '--name', 'program', '--no-nodejs-compat', '--no-namespaced-exports', '--instantiation', 'async', '--base64-cutoff=0', '--async-mode', 'jspi', '--async-wasi-imports', '--async-wasi-exports', '--bindgen-enable-wasm-exnref'], { env });
	regularFile(path.join(outputDir, 'program.js'));
	const modules = fs.readdirSync(outputDir).filter((name) => name.endsWith('.wasm')).sort();
	if (!modules.length) throw new Error('Jco did not emit any core WebAssembly modules.');
	for (const name of modules) {
		const bytes = regularFile(path.join(outputDir, name));
		if (!bytes.subarray(0, 8).equals(Buffer.from([0, 97, 115, 109, 1, 0, 0, 0]))) throw new Error(`Jco emitted an invalid core WebAssembly header: ${name}`);
	}
	return { entry: 'program.js', modules };
}

/**
 * Compile generated PHP sources without running them. Use fixed source paths
 * because TypePHP embeds absolute paths even without debug symbols. Lock each
 * source set during the build and publish after compilation and Jco succeed.
 */
export function compileTypePhp({ sources, outputDir, compiler = process.env.ATOMS_TYPEPHP_COMPILER || 'tpc', phpxHome = process.env.PHPX_HOME, jco = 'jco', env = process.env }) {
	const entries = Object.entries(sources).sort(([a], [b]) => a < b ? -1 : a > b ? 1 : 0);
	for (const [name, source] of entries) {
		if (!name.startsWith('/') || name === '/' || name.includes('\\') || name.includes('\0') || path.posix.normalize(name) !== name || name.endsWith('/') || typeof source !== 'string') {
			throw new Error(`Invalid TypePHP source path or contents: ${JSON.stringify(name)}`);
		}
	}
	if (!entries.some(([name]) => name.endsWith('.php'))) throw new Error('TypePHP requires at least one PHP source file.');
	const { environment, fingerprint } = preflightTypePhp({ compiler, phpxHome, env });
	const hash = digest(JSON.stringify({ format: FORMAT, sources: entries, toolchain: fingerprint, jco: '1.41.0', epoch: 0 }));
	directory(CACHE, true);
	const lock = path.join(CACHE, `${hash}.lock`);
	const destination = path.resolve(outputDir);
	const outputLock = path.join(CACHE, `output-${digest(destination)}.lock`);
	let lockFd;
	try { lockFd = fs.openSync(lock, 'wx', 0o600); } catch (error) { if (error.code === 'EEXIST') throw new Error(`A TypePHP build is already using ${hash}; wait for it to finish or remove its stale lock after confirming it has stopped: ${lock}`); throw error; }
	let outputLockFd;
	let temporary;
	try {
		try { outputLockFd = fs.openSync(outputLock, 'wx', 0o600); } catch (error) { if (error.code === 'EEXIST') throw new Error(`A TypePHP build is already writing ${destination}: ${outputLock}`); throw error; }
		const root = directory(path.join(CACHE, hash), true);
		const sourcePaths = [];
		for (const [name, source] of entries) {
			const file = path.join(root, 'sources', name.slice(1));
			directory(path.dirname(file), true);
			try { fs.writeFileSync(file, source, { flag: 'wx', mode: 0o600 }); } catch (error) { if (error.code !== 'EEXIST') throw error; if (!regularFile(file).equals(Buffer.from(source))) throw new Error(`TypePHP immutable staging source differs: ${file}`); }
			if (name.endsWith('.php')) sourcePaths.push(file);
		}
		directory(path.dirname(destination));
		if (fs.existsSync(destination)) {
			if (!fs.lstatSync(destination).isDirectory()) throw new Error(`TypePHP output must be a plain directory: ${destination}`);
			const marker = path.join(destination, 'typephp-build.json');
			if (!fs.existsSync(marker) || JSON.parse(regularFile(marker)).format !== FORMAT) throw new Error(`Refusing to replace a directory not produced by TypePHP bundling: ${destination}`);
		}
		temporary = fs.mkdtempSync(path.join(path.dirname(destination), '.atoms-typephp-'));
		const work = fs.mkdtempSync(path.join(root, 'build-'));
		try {
			fs.writeFileSync(path.join(work, 'project.yml'), JSON.stringify({ name: 'atoms', mode: 'bin', wasm: 'component', 'build-dir': path.join(work, 'native'), output: path.join(temporary, 'program.wasm'), sources: sourcePaths }));
			run(compiler, [path.join(work, 'project.yml')], { cwd: work, env: environment });
		} finally { fs.rmSync(work, { recursive: true, force: true }); }
		const component = regularFile(path.join(temporary, 'program.wasm'));
		if (!component.subarray(0, 8).equals(COMPONENT_HEADER)) throw new Error('TypePHP did not emit a WASI component (invalid WebAssembly component header).');
		const js = transpileTypePhp({ componentPath: path.join(temporary, 'program.wasm'), outputDir: path.join(temporary, 'js'), jco, env: environment });
		const metadata = { format: FORMAT, sourceHash: hash, componentHash: digest(component), toolchain: fingerprint, component: 'program.wasm', entry: `js/${js.entry}`, modules: js.modules.map((name) => `js/${name}`) };
		fs.writeFileSync(path.join(temporary, 'typephp-build.json'), JSON.stringify(metadata, null, 2) + '\n');
		let previous;
		if (fs.existsSync(destination)) { previous = temporary + '.previous'; fs.renameSync(destination, previous); }
		try { fs.renameSync(temporary, destination); temporary = undefined; } catch (error) { if (previous) fs.renameSync(previous, destination); throw error; }
		if (previous) fs.rmSync(previous, { recursive: true, force: true });
		return {
			...metadata,
			componentPath: path.join(destination, metadata.component),
			jcoPath: path.join(destination, metadata.entry),
			entryPath: path.join(destination, metadata.entry),
			modulePaths: metadata.modules.map((name) => path.join(destination, name)),
			coreModules: Object.fromEntries(metadata.modules.map((name) => [path.basename(name), path.join(destination, name)])),
			compilerVersion: '0.9.5', phpAbi: '8.5',
		};
	} finally {
		if (temporary) fs.rmSync(temporary, { recursive: true, force: true });
		if (outputLockFd !== undefined) { fs.closeSync(outputLockFd); fs.unlinkSync(outputLock); }
		fs.closeSync(lockFd);
		fs.unlinkSync(lock);
	}
}
