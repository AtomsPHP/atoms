// Run after npm run prepare-runtime to refresh the CLI inventory.
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

try {
	const response = await php.run({ code: `<?php
		$extensions = [];
		foreach (get_loaded_extensions() as $name) {
			$extensions[strtolower($name)] = phpversion($name);
		}
		// These modules implement the php-wasm host bridge.
		unset($extensions['post_message_to_js'], $extensions['dns_polyfill'], $extensions['wasm_memory_storage']);
		// The static build also registers an empty module named opcache.
		unset($extensions['opcache']);
		ksort($extensions, SORT_STRING);

		$classes = [];
		foreach (array_merge(get_declared_classes(), get_declared_interfaces(), get_declared_traits()) as $name) {
			$class = new ReflectionClass($name);
			if ($class->isInternal()) {
				$classes[strtolower($name)] = strtolower($class->getExtensionName());
			}
		}
		ksort($classes, SORT_STRING);

		$functions = [];
		foreach (get_defined_functions()['internal'] as $name) {
			$functions[strtolower($name)] = strtolower((new ReflectionFunction($name))->getExtensionName());
		}
		ksort($functions, SORT_STRING);
		echo json_encode(compact('extensions', 'classes', 'functions'), JSON_THROW_ON_ERROR);
	` });
	if (response.exitCode !== 0 || response.errors) throw new Error(response.errors || 'PHP probe failed');

	const { extensions, classes, functions } = JSON.parse(response.text);
	for (const [file, data] of Object.entries({
		'runtime-extensions.json': { extensions, classes },
		'runtime-functions.json': functions,
	})) {
		const path = new URL(`../../../packages/cli/resources/${file}`, import.meta.url);
		const content = JSON.stringify(data, null, 4) + '\n';
		if (process.argv.includes('--check')) {
			if (readFileSync(path, 'utf8') !== content) {
				throw new Error(`Run node scripts/gen-runtime-functions.mjs to refresh ${file}.`);
			}
		} else {
			writeFileSync(path, content);
		}
	}
} finally {
	php.exit();
}
