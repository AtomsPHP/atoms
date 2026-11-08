import fs from 'node:fs';
import path from 'node:path';

/** Local process fixtures that do not compile or execute customer PHP. */
export function fakeTypePhpTools(scratch) {
	const bin = path.join(scratch, 'bin');
	const phpxHome = path.join(scratch, 'phpx');
	const sdk = path.join(phpxHome, 'wasm/wasm32-wasip2');
	fs.mkdirSync(bin);
	fs.mkdirSync(path.join(sdk, 'lib'), { recursive: true });
	fs.writeFileSync(path.join(sdk, '.typephp-wasi-sdk-abi'), 'typephp-wasip2-sdk-abi-v4\n');
	for (const name of ['php', 'phpx', 'gmp', 'gmpxx', 'mpfr', 'mpdec', 'mpdec++']) fs.writeFileSync(path.join(sdk, 'lib', `lib${name}.a`), name);
	for (const name of ['php/main/php.h', 'php/main/php_config.h', 'php/Zend/zend_config.h', 'php/TSRM/TSRM.h', 'phpx/phpx.h', 'phpx/phpx_exception_policy.h', 'phpx/phpx_cast_policy.h', 'phpx/typephp_runtime.h', 'phpx/typephp_opcode_table.h', 'phpx/typephp_helper.h']) {
		fs.mkdirSync(path.dirname(path.join(sdk, 'include', name)), { recursive: true });
		fs.writeFileSync(path.join(sdk, 'include', name), name);
	}

	// Local executables test arguments, environment variables, and process failures.
	// They do not execute the PHP inputs.
	const fake = `#!${process.execPath}
	const fs = require('node:fs');
	const path = require('node:path');
	const crypto = require('node:crypto');
	const name = path.basename(process.argv[1]);
	const args = process.argv.slice(2);
	if (process.env.CLOUDFLARE_API_TOKEN || process.env.ATOMS_SHARED_SECRET) throw new Error('Build tools received deployment credentials');
	if (args[0] === '--version') {
	  console.log(name === 'tpc' ? 'TypePHP Compiler (AOT) v0.9.5' : name === 'jco' ? '1.41.0' : name === 'wasmtime' ? 'wasmtime 47.0.0 (test)' : name === 'wasm-component-ld' ? 'wasm-component-ld 0.5.22' : 'LLVM version 22.1.0');
	} else if (args[0] === '-dumpmachine') {
	  console.log('wasm32-unknown-wasip2');
	} else if (name === 'llvm-nm') {
	  for (const symbol of ['typephp_install_reflection_attribute_handlers()', 'typephp_uninstall_reflection_attribute_handlers()', 'php::FunctionCallCacheSlot::~FunctionCallCacheSlot()', 'php::MethodCallCacheSlot::~MethodCallCacheSlot()', 'typephp_runtime_start', 'typephp_runtime_stop', 'typephp_read_property_cached(php::Variant const&, php::String const&, php::AttrMode, php::PropertyCacheSlot&)', 'typephp_write_property_scoped(php::Variant const&, php::Variant const&, php::Variant const&, _zend_class_entry*)']) {
	    console.log('00000001 ' + (process.env.FAKE_MISSING_SYMBOL && symbol === 'typephp_runtime_start' ? 'U' : 'T') + ' ' + symbol);
	  }
	} else if (name === 'tpc') {
	  fs.appendFileSync(process.env.FAKE_CALLS, JSON.stringify({ args, epoch: process.env.SOURCE_DATE_EPOCH }) + '\\n');
	  if (args.length !== 1 || !args[0].endsWith('project.yml')) throw new Error('Unexpected compiler arguments');
	  const config = JSON.parse(fs.readFileSync(args[0], 'utf8'));
	  const source = config.sources.map(file => file + fs.readFileSync(file, 'utf8')).join('');
	  const hash = crypto.createHash('sha256').update(source).digest();
	  const header = Buffer.from(process.env.FAKE_BAD_HEADER ? [0,97,115,109,1,0,0,0] : [0,97,115,109,13,0,1,0]);
	  fs.writeFileSync(config.output, Buffer.concat([header, hash]));
	  if (process.env.FAKE_COMPILE_FAILURE) { console.error('compiler diagnostic'); process.exit(23); }
	} else if (name === 'jco') {
	  if (process.env.FAKE_JCO_FAILURE) { console.error('transpiler diagnostic'); process.exit(24); }
	  for (const arg of ['--async-mode','jspi','--instantiation','async','--bindgen-enable-wasm-exnref']) if (!args.includes(arg)) throw new Error('Missing required Jco argument: ' + arg);
	  const out = args[args.indexOf('-o') + 1];
	  fs.mkdirSync(out, { recursive: true });
	  fs.writeFileSync(path.join(out, 'program.js'), 'export function instantiate() {}\\n');
	  for (const file of ['program.core.wasm', 'program.conditional-host-import-0.wasm']) fs.writeFileSync(path.join(out,file), Buffer.from([0,97,115,109,1,0,0,0]));
	} else { throw new Error('Unexpected invocation: ' + name); }
	`;
	for (const name of ['tpc', 'jco', 'wasmtime', 'wasm32-wasip2-clang', 'wasm32-wasip2-clang++', 'llvm-ar', 'llvm-ranlib', 'llvm-nm', 'wasm-component-ld']) fs.writeFileSync(path.join(bin, name), fake, { mode: 0o700 });
	const calls = path.join(scratch, 'calls.jsonl');
	const env = { ...process.env, PATH: bin, FAKE_CALLS: calls, CLOUDFLARE_API_TOKEN: 'test-token', ATOMS_SHARED_SECRET: 'test-secret' };
	return { bin, phpxHome, sdk, calls, env };
}
