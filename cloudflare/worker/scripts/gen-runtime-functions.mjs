// Run after npm run prepare-runtime. The CLI uses these lists to check built-in
// functions and extensions offline and to pin Composer's Worker platform map.
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
		// WordPress Playground's internal wasm/host C extensions (see
		// cloudflare/THIRD_PARTY_NOTICES.md). They are runtime plumbing rather
		// than customer-facing PHP extensions and must not be exposed to Atom
		// code or Composer's platform map.
		$excludedExtensions = [
			'dns_polyfill' => true,
			'post_message_to_js' => true,
			'wasm_memory_storage' => true,
		];

		$loaded = [];
		foreach (get_loaded_extensions() as $extension) {
			$lower = strtolower($extension);
			if (isset($excludedExtensions[$lower])) {
				continue;
			}
			$loaded[$lower] = true;
		}
		// php-wasm compiles opcache statically and reports both "opcache" (0
		// symbols) and "Zend OPcache" (which owns all opcache_* functions and
		// maps to Composer's ext-zend-opcache). Standard PHP reports only
		// "Zend OPcache".
		if (isset($loaded['zend opcache'])) {
			unset($loaded['opcache']);
		}
		$extensions = array_keys($loaded);
		sort($extensions, SORT_STRING);

		$functions = [];
		foreach (get_defined_functions()['internal'] as $name) {
			$ext = strtolower((string) (new ReflectionFunction($name))->getExtensionName());
			if (isset($excludedExtensions[$ext])) {
				continue;
			}
			if (!isset($loaded[$ext])) {
				fwrite(STDERR, "Function {$name} belongs to unloaded extension {$ext}\\n");
				exit(1);
			}
			$functions[strtolower($name)] = $ext;
		}
		ksort($functions, SORT_STRING);

		echo json_encode([
			'extensions' => $extensions,
			'functions' => $functions,
		], JSON_THROW_ON_ERROR);
	` });
	if (response.exitCode !== 0 || response.errors) throw new Error(response.errors || 'PHP probe failed');

	const probe = JSON.parse(response.text);
	const files = [
		{
			name: 'packages/cli/resources/runtime-functions.json',
			url: new URL('../../../packages/cli/resources/runtime-functions.json', import.meta.url),
			content: `${JSON.stringify(probe.functions, null, 4)}\n`,
		},
		{
			name: 'packages/cli/resources/runtime-extensions.json',
			url: new URL('../../../packages/cli/resources/runtime-extensions.json', import.meta.url),
			content: `${JSON.stringify({
				$comment:
					'Generated from the pinned php-wasm Worker runtime by cloudflare/worker/scripts/gen-runtime-functions.mjs. Do not edit by hand. A built-in symbol whose defining extension is not on this list is a build error (ATOMS-E019). Compared case-insensitively against ReflectionClass/ReflectionFunction::getExtensionName().',
				extensions: probe.extensions,
			}, null, 4)}\n`,
		},
	];

	if (process.argv.includes('--check')) {
		const stale = files.filter(({ url, content }) => readFileSync(url, 'utf8') !== content);
		if (stale.length > 0) {
			const names = stale.map(({ name }) => name).join(', ');
			throw new Error(
				`Stale CLI runtime inventory (${names}). Run node scripts/gen-runtime-functions.mjs in cloudflare/worker to refresh.`,
			);
		}
	} else {
		for (const { url, content } of files) {
			writeFileSync(url, content);
		}
	}
} finally {
	php.exit();
}
