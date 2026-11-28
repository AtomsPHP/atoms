import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { runGuestProbe } from '../worker/scripts/lib/php-probe.mjs';

const [worker, loaderPath, phpVersion] = process.argv.slice(2);
if (!phpVersion) throw new Error('Usage: node inspect.mjs <worker directory> <loader path> <PHP version>');

const { bootPHP } = await import(pathToFileURL(resolve(worker, 'src/php-host.js')));
const entry = await import(pathToFileURL(resolve(loaderPath)));
const factory = entry.factory ?? entry.default;
const wasm = entry.wasm ?? new WebAssembly.Module(readFileSync(loaderPath.replace(/\.js$/, '.wasm')));
const imports = WebAssembly.Module.imports(wasm);
const names = new Set(WebAssembly.Module.exports(wasm).map(entry => entry.name));
for (const name of ['atoms_send', 'atoms_pdo_leaf', 'atoms_startup']) {
	if (!imports.some(entry => entry.module === 'env' && entry.name === name)) throw Error(`Missing Wasm import ${name}`);
}
for (const name of ['atoms_prepare', 'php_embed_init', 'php_execute_script', 'php_embed_shutdown']) {
	if (!names.has(name)) throw Error(`Missing Wasm export ${name}`);
}
if (imports.some(entry => /^(GOT\.|dylink)/.test(entry.module) || entry.name === '__asyncjs__js_module_onMessage')) {
	throw Error('Unexpected dynamic-linking or legacy message import');
}
globalThis.fetch = () => { throw Error('Packed PHP attempted to fetch an artifact'); };
const code = readFileSync(new URL('./inspect-guest.php', import.meta.url), 'utf8');
const report = await runGuestProbe(bootPHP, { factory, wasm }, code);
if (report.behavior.php !== phpVersion || !report.behavior.openssl_version.startsWith('OpenSSL 3.5.9 ') ||
	report.behavior.sqlite_int64 !== '9223372036854775807' || !report.behavior.openssl_ec_sign_verify) {
	throw new Error('The packed interpreter failed its PHP, OpenSSL or SQLite probe');
}
console.log(JSON.stringify(report, null, 2));
