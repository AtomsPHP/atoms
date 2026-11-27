// Consume the three qualified tarballs in their registry form. CI seeds npm's
// content-addressed cache. The protected tag job publishes the identical files.
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { createReadStream, existsSync, readFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const recipe = fileURLToPath(new URL('.', import.meta.url));
const release = JSON.parse(readFileSync(resolve(recipe, '../../release/manifest.json'), 'utf8'));
const [action, input, scaffold] = process.argv.slice(2);
if (!['seed', 'publish-interpreters', 'publish-scaffold'].includes(action) || !input) {
	throw new Error('Usage: node artifacts.mjs seed|publish-interpreters|publish-scaffold <directory> [scaffold.tgz]');
}

function packedJson(path, member) {
	return JSON.parse(execFileSync('tar', ['-xOzf', path, 'package/' + member], { encoding: 'utf8' }));
}

async function hashes(path) {
	const sha256 = createHash('sha256');
	const sha512 = createHash('sha512');
	let size = 0;
	for await (const chunk of createReadStream(path)) {
		size += chunk.length;
		sha256.update(chunk);
		sha512.update(chunk);
	}
	return { sha256: sha256.digest('hex'), integrity: 'sha512-' + sha512.digest('base64'), size };
}

async function publish(path, name, version, integrity) {
	const url = 'https://registry.npmjs.org/' + encodeURIComponent(name) + '/' + encodeURIComponent(version);
	let response = await fetch(url);
	if (response.status === 404) {
		execFileSync(process.env.PUBLISH_NPM || 'npm', ['publish', path, '--access', 'public'], { stdio: 'inherit' });
		response = await fetch(url);
	}
	if (!response.ok) throw new Error(`Registry returned HTTP ${response.status} for ${name}@${version}`);
	const published = await response.json();
	if (published.name !== name || published.version !== version || published.dist?.integrity !== integrity) {
		throw new Error(`Registry integrity differs from the qualified ${name}@${version}`);
	}
	console.log(`Verified ${name}@${version} ${integrity}`);
}

if (action === 'publish-scaffold') {
	const path = resolve(input);
	const manifest = packedJson(path, 'package.json');
	if (manifest.name !== release.runtime.package || manifest.version !== release.runtime.version) {
		throw new Error('Scaffold tarball does not match the coordinated release manifest');
	}
	await publish(path, manifest.name, manifest.version, (await hashes(path)).integrity);
} else {
	const packages = release.runtime.guest_php_tested.map(series => {
		const pin = JSON.parse(readFileSync(join(recipe, 'targets', series, 'candidate.json'), 'utf8'));
		const filename = pin.package.slice(1).replace('/', '-') + '-' + pin.version + '.tgz';
		const flat = join(input, filename);
		const path = resolve(existsSync(flat) ? flat : join(input, 'interpreter-' + series, filename));
		return { pin, path };
	});
	const lock = action === 'publish-interpreters' ? packedJson(resolve(scaffold), 'template/package-lock.json') : null;
	let packed = 0;
	let unpacked = 0;
	for (const { pin, path } of packages) {
		const actual = await hashes(path);
		if (actual.sha256 !== pin.sha256 || actual.integrity !== pin.integrity || actual.size !== pin.packedBytes) {
			throw new Error(`Qualified tarball differs from the release pin for ${pin.package}`);
		}
		if (lock) {
			const dependency = lock.packages['node_modules/' + pin.package];
			if (lock.packages[''].dependencies[pin.package] !== pin.version ||
				dependency?.version !== pin.version || dependency?.integrity !== pin.integrity) {
				throw new Error(`Packed scaffold lock differs from ${pin.package}@${pin.version}`);
			}
		}
		packed += actual.size;
		unpacked += pin.unpackedBytes;
	}
	console.log(`Three interpreter packages total ${packed} bytes packed and ${unpacked} bytes unpacked.`);
	for (const { pin, path } of packages) {
		if (action === 'seed') execFileSync('npm', ['cache', 'add', path], { stdio: 'inherit' });
		else await publish(path, pin.package, pin.version, pin.integrity);
	}
}
