import { readFileSync } from 'node:fs';
import { createRequire, registerHooks } from 'node:module';
import { resolve } from 'node:path';
import { pathToFileURL } from 'node:url';

const [worker, loaderPath, phpVersion] = process.argv.slice(2);
if (!phpVersion) throw new Error('Usage: node inspect.mjs <worker directory> <loader path> <PHP version>');

// Wrangler imports Wasm as a compiled module. Supply the same input to the
// real host under Node so it can inspect the packed bytes before publication.
registerHooks({
	load(url, context, next) {
		if (!url.endsWith('.wasm')) return next(url, context);
		return {
			format: 'module',
			shortCircuit: true,
			source: "import { readFileSync } from 'node:fs'; export default new WebAssembly.Module(readFileSync(new URL(import.meta.url)));",
		};
	},
});
globalThis.require = createRequire(import.meta.url);
const { bootPHP } = await import(pathToFileURL(resolve(worker, 'src/php-host.js')));
const loader = await import(pathToFileURL(resolve(loaderPath)));
const php = await bootPHP(loader, { onSync() {}, onPark() {} });
try {
	const code = readFileSync(new URL('./inspect-guest.php', import.meta.url), 'utf8');
	const result = await php.run({ code });
	if (result.exitCode || result.errors) throw new Error(result.errors || 'Guest inspection failed');
	const report = JSON.parse(result.text);
	if (report.behavior.php !== phpVersion || !report.behavior.openssl_version.startsWith('OpenSSL 3.5.9 ') ||
		report.behavior.sqlite_int64 !== '9223372036854775807' || !report.behavior.openssl_ec_sign_verify) {
		throw new Error('The packed interpreter failed its PHP, OpenSSL or SQLite probe');
	}
	console.log(JSON.stringify(report, null, 2));
} finally {
	php.exit();
}
