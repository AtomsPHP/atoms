// Generate the scaffold's interpreter pins and registry lock entries without
// contacting npm. The release job publishes these exact prequalified tarballs.
import { readFileSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const worker = new URL('../', import.meta.url);
const read = path => JSON.parse(readFileSync(new URL(path, worker), 'utf8'));
const sorted = object => Object.fromEntries(Object.keys(object).sort().map(key => [key, object[key]]));

export function updateInterpreters(check) {
	const release = read('../../release/manifest.json');
	const manifest = read('package.json');
	const lock = read('package-lock.json');
	const pins = {};
	for (const series of release.runtime.guest_php_tested) {
		const pin = read(`../php-wasm-build/targets/${series}/candidate.json`);
		const suffix = series.replace('.', '-');
		const stem = 'php_' + series.replace('.', '_');
		if (pin.package !== '@atomsphp/php-wasm-' + suffix || !/^\d+\.\d+\.\d+-atoms\.\d+$/.test(pin.version) ||
			!pin.version.startsWith(series + '.') || !/^sha512-[A-Za-z0-9+/]{86}==$/.test(pin.integrity ?? '') ||
			pin.wasm.path !== stem + '.wasm' || pin.glue.path !== stem + '.js') {
			throw new Error(`Invalid interpreter pin for PHP ${series}.`);
		}
		for (const file of [pin.wasm, pin.glue]) {
			if (!(Number.isSafeInteger(file.bytes) && file.bytes > 0 && /^[a-f0-9]{64}$/.test(file.sha256))) {
				throw new Error(`Invalid interpreter file record for ${file.path}.`);
			}
		}
		pins[series] = pin;
		manifest.dependencies[pin.package] = pin.version;
		lock.packages['node_modules/' + pin.package] = {
			version: pin.version,
			resolved: `https://registry.npmjs.org/${pin.package}/-/php-wasm-${suffix}-${pin.version}.tgz`,
			integrity: pin.integrity,
			license: 'SEE LICENSE IN THIRD_PARTY_NOTICES.txt',
		};
	}
	const owned = new Set(Object.values(pins).map(pin => pin.package));
	for (const name of [...Object.keys(manifest.dependencies), ...Object.keys(lock.packages).map(key => key.replace(/^node_modules\//, ''))]) {
		if (/^(?:@php-wasm\/web-|@atomsphp\/php-wasm-)/.test(name) && !owned.has(name)) {
			throw new Error(`Unexpected interpreter dependency ${name}.`);
		}
	}
	manifest.dependencies = sorted(manifest.dependencies);
	lock.packages[''].dependencies = manifest.dependencies;
	lock.packages = sorted(lock.packages);
	for (const [file, value, indent] of [
		['release/interpreters.json', pins, 2], ['package.json', manifest, '\t'], ['package-lock.json', lock, '\t'],
	]) {
		const path = new URL(file, worker);
		const content = JSON.stringify(value, null, indent) + '\n';
		if (check) {
			if (readFileSync(path, 'utf8') !== content) throw new Error(`Stale ${file}. Run node scripts/update-interpreters.mjs in cloudflare/worker.`);
		} else {
			writeFileSync(path, content);
		}
	}
}
if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
	updateInterpreters(process.argv.includes('--check'));
}
