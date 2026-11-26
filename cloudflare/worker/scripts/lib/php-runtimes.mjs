/** WordPress Playground Asyncify artifacts verified by prepare-runtime.mjs. */
export const PLAYGROUND_VERSION = '3.1.48';
export const PHP_RUNTIMES = {
	'8.3': {
		version: '8.3.32',
		wasmBytes: 18309089,
		wasmSha256: 'eca478d2bad4cae984cd5b5ec39ce42311fcc4d31cf48fce7293e9a5034f1c98',
		glueBytes: 268881,
		glueSha256: '719346ea1827cc48c8dd298974649503b7a227a7c0e5c12827db1c993dccf3ac',
	},
	'8.4': {
		version: '8.4.23',
		wasmBytes: 19943873,
		wasmSha256: '06c33a34294ff0ef39a9b65d140e569a0aad216d3e8e042299cf15639430f94c',
		glueBytes: 270583,
		glueSha256: 'd6077aabfb9af35ed1e75bb5f197645b175edf1981d57671348aa942abe29ad8',
	},
	'8.5': {
		version: '8.5.8',
		wasmBytes: 21019221,
		wasmSha256: 'ed97ea5422dbf23e0687c98203cc8c6d7f2772a3d71b03d4991d2fdd93a048f0',
		glueBytes: 270942,
		glueSha256: '0ed44eae7cd040826775b3dcd5376bb86a14257ae9fe1ff5cda1b7daf7572088',
	},
};

export function phpRuntime(series) {
	if (typeof series !== 'string' || !Object.hasOwn(PHP_RUNTIMES, series)) {
		throw new Error(`Unsupported Worker PHP version ${JSON.stringify(series)}. Choose ${Object.keys(PHP_RUNTIMES).join(', ')}.`);
	}
	const pin = PHP_RUNTIMES[series];
	const basename = `php_${series.replace('.', '_')}`;
	return {
		...pin,
		packageName: `@php-wasm/web-${series.replace('.', '-')}`,
		glue: `${basename}.js`,
		wasm: `${pin.version.replaceAll('.', '_')}/${basename}.wasm`,
	};
}
