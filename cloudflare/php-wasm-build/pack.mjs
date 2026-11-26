// Usage: node pack.mjs <first clean output> <second clean output> <version> <destination>
// Run after comparing independent builds. Keep the tgz for promotion and
// review interpreter.json in the scaffold release independently of the tgz.
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { copyFileSync, mkdirSync, mkdtempSync, readFileSync, realpathSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';

const [first, second, version, dest] = process.argv.slice(2);
if (!dest || !/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/.test(version)) {
	throw new Error('Usage: node pack.mjs <first clean output> <second clean output> <exact version> <destination>');
}
if (realpathSync(first) === realpathSync(second)) throw new Error('Supply two independent build outputs.');
const name = '@atomsphp/php-wasm-8-3';
const pin = { package: name, version };
const destination = resolve(dest);
mkdirSync(destination, { recursive: true });
const stage = mkdtempSync(join(destination, '.pack-'));
try {
	for (const [key, path] of Object.entries({ wasm: 'php_8_3.wasm', glue: 'php_8_3.js' })) {
		const bytes = readFileSync(join(first, path));
		if (!bytes.equals(readFileSync(join(second, path)))) {
			throw new Error(`Independent builds differ for ${path}.`);
		}
		pin[key] = { path, bytes: bytes.length, sha256: createHash('sha256').update(bytes).digest('hex') };
		writeFileSync(join(stage, path), bytes);
	}
	for (const file of ['corresponding-source.tar.gz', 'THIRD_PARTY_NOTICES.txt', 'components.json']) {
		copyFileSync(join(first, file), join(stage, file));
	}
	const files = ['php_8_3.wasm', 'php_8_3.js', 'corresponding-source.tar.gz', 'THIRD_PARTY_NOTICES.txt', 'components.json'];
	const manifest = { name, version, type: 'module', license: 'SEE LICENSE IN THIRD_PARTY_NOTICES.txt', files,
		repository: { type: 'git', url: 'git+https://github.com/AtomsPHP/atoms.git', directory: 'cloudflare/php-wasm-build' },
		publishConfig: { access: 'public', provenance: true },
	};
	writeFileSync(join(stage, 'package.json'), JSON.stringify(manifest, null, 2) + '\n');
	const result = JSON.parse(execFileSync('npm', ['pack', '--json', '--ignore-scripts', '--pack-destination', destination], {
		cwd: stage, encoding: 'utf8',
	}))[0];
	for (const file of files) {
		if (!result.files.some(({ path }) => path === file)) throw new Error(`Missing from npm pack: ${file}`);
	}
	writeFileSync(join(destination, 'interpreter.json'), JSON.stringify(pin, null, 2) + '\n');
	console.log(JSON.stringify({ filename: result.filename, integrity: result.integrity, pin }, null, 2));
} finally {
	rmSync(stage, { recursive: true, force: true });
}
