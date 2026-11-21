// Run after npm run prepare-runtime. The CLI uses this list to check built-in
// functions and their extensions offline.
import { readFileSync, writeFileSync } from 'node:fs';
import { createRequire, registerHooks } from 'node:module';

// Wrangler imports .wasm files as compiled WebAssembly modules.
registerHooks({
	load(url, context, nextLoad) {
		if (url.endsWith('.wasm')) {
			return {
				format: 'module',
				shortCircuit: true,
				source: `import { readFileSync } from 'node:fs';
					export default new WebAssembly.Module(readFileSync(new URL(import.meta.url)));`,
			};
		}
		return nextLoad(url, context);
	},
});

// The upstream Emscripten Node adapter uses CommonJS to load node:events.
globalThis.require = createRequire(import.meta.url);
const { bootPHP } = await import('../src/php-host.js');
const php = await bootPHP({ onSync() {}, onPark() {} });
const response = await php.run({ code: `<?php
	$functions = [];
	foreach (get_defined_functions()['internal'] as $name) {
		$functions[strtolower($name)] = strtolower((new ReflectionFunction($name))->getExtensionName());
	}
	ksort($functions, SORT_STRING);
	echo json_encode($functions, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\\n";
` });
if (response.exitCode !== 0 || response.errors) throw new Error(response.errors || 'PHP probe failed');

const output = new URL('../../../packages/cli/resources/runtime-functions.json', import.meta.url);
if (process.argv.includes('--check')) {
	if (readFileSync(output, 'utf8') !== response.text) {
		throw new Error('Run node scripts/gen-runtime-functions.mjs to refresh the CLI function list.');
	}
} else {
	writeFileSync(output, response.text);
}
php.exit();
