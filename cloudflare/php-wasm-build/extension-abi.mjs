// Reproduce the linker's web ABI from Playground's reference intl side module.
// Only import names are retained. No extension code enters the Worker.
import { readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

const [moduleFile, dest] = process.argv.slice(2);
const imports = WebAssembly.Module.imports(new WebAssembly.Module(readFileSync(moduleFile)));
const js = imports.filter(({ module, kind, name }) => module === 'env' &&
	(kind === 'function' || kind === 'global') && !/^(invoke_|_Z)/.test(name)).map(({ name }) => '_' + name);
const wasm = imports.filter(({ module, kind }) => kind === 'global' &&
	(module === 'GOT.mem' || module === 'GOT.func')).map(({ name }) => name);
for (const [path, names] of [['js-exports.txt', js], ['wasm-exports.txt', wasm]]) {
	if (!names.length) throw new Error('Reference module has no ' + path);
	writeFileSync(join(dest, path), [...new Set(names)].sort().join('\n') + '\n');
}
