// Run after npm run prepare-runtime to refresh the CLI and PHPStan inventories.
import { readFileSync, writeFileSync } from 'node:fs';
import { createRequire, registerHooks } from 'node:module';
import { PHP_RUNTIMES, phpRuntime } from './lib/php-runtimes.mjs';

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
const probe = `<?php
	// These modules implement the php-wasm host bridge and must not be
	// exposed to Atom code or Composer's platform map.
	$excluded = [
		'post_message_to_js' => true,
		'dns_polyfill' => true,
		'wasm_memory_storage' => true,
	];

	$extensions = [];
	foreach (get_loaded_extensions() as $name) {
		$lower = strtolower($name);
		if (isset($excluded[$lower])) {
			continue;
		}
		$extensions[$lower] = phpversion($name);
	}
	// The static build also registers an empty module named opcache.
	unset($extensions['opcache']);
	ksort($extensions, SORT_STRING);

	$classes = [];
	foreach (array_merge(get_declared_classes(), get_declared_interfaces(), get_declared_traits()) as $name) {
		$class = new ReflectionClass($name);
		if (!$class->isInternal()) {
			continue;
		}
		$ext = strtolower((string) $class->getExtensionName());
		if (isset($excluded[$ext])) {
			continue;
		}
		if (!isset($extensions[$ext])) {
			fwrite(STDERR, "Class {$name} belongs to unlisted extension {$ext}\\n");
			exit(1);
		}
		$classes[strtolower($name)] = $ext;
	}
	ksort($classes, SORT_STRING);

	$functions = [];
	foreach (get_defined_functions()['internal'] as $name) {
		$ext = strtolower((string) (new ReflectionFunction($name))->getExtensionName());
		if (isset($excluded[$ext])) {
			continue;
		}
		if (!isset($extensions[$ext])) {
			fwrite(STDERR, "Function {$name} belongs to unlisted extension {$ext}\\n");
			exit(1);
		}
		$functions[strtolower($name)] = $ext;
	}
	ksort($functions, SORT_STRING);
	echo json_encode(compact('extensions', 'classes', 'functions'), JSON_THROW_ON_ERROR);
`;

function writeInventory(relativePath, data) {
	const path = new URL(`../../../packages/${relativePath}`, import.meta.url);
	const content = JSON.stringify(data, null, 4) + '\n';
	if (process.argv.includes('--check')) {
		if (readFileSync(path, 'utf8') !== content) {
			throw new Error(`Run node scripts/gen-runtime-functions.mjs to refresh ${relativePath}.`);
		}
	} else {
		writeFileSync(path, content);
	}
}

const namespacedBuiltins = new Set();
for (const series of Object.keys(PHP_RUNTIMES)) {
	const runtime = phpRuntime(series);
	const loader = await import(`../.php-wasm/${runtime.glue.path}`);
	const php = await bootPHP(loader, { onSync() {}, onPark() {} });
	let response;
	try {
		response = await php.run({ code: probe });
	} finally {
		php.exit();
	}
	if (response.exitCode !== 0 || response.errors) throw new Error(response.errors || 'PHP probe failed');

	const { extensions, classes, functions } = JSON.parse(response.text);
	if (extensions.core !== runtime.php) throw new Error(`Expected PHP ${runtime.php}, found ${extensions.core}`);
	const suffix = series === '8.3' ? '' : `-${series}`;
	writeInventory(`cli/resources/runtime-extensions${suffix}.json`, { extensions, classes });
	writeInventory(`cli/resources/runtime-functions${suffix}.json`, functions);
	for (const name of Object.keys(classes)) {
		if (name.includes('\\')) namespacedBuiltins.add(name);
	}
}

// PHPStan's boundary rules distinguish Worker built-ins from application
// classes even if the analysis PHP version has no reflection data for them.
writeInventory('phpstan-rules/resources/namespaced-worker-builtins.json', [...namespacedBuiltins].sort());
