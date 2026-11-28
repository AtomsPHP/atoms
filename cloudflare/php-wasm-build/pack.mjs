// Supply the full first output and the independent second build record. The
// record may come from a clean runner without transporting its interpreter.
// Run after comparing independent builds. Keep the tgz for promotion and
// review interpreter.json in the scaffold release independently of the tgz.
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { copyFileSync, mkdirSync, mkdtempSync, readFileSync, realpathSync, renameSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const [first, second, version, dest] = process.argv.slice(2);
if (!dest || !/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/.test(version)) {
	throw new Error('Usage: node pack.mjs <first clean output> <independent build-metadata.json> <exact version> <destination>');
}
const localRecord = join(first, 'build-metadata.json');
if (realpathSync(localRecord) === realpathSync(second)) throw new Error('Supply two independent build records.');
const metadata = readFileSync(localRecord);
if (!metadata.equals(readFileSync(second))) {
	throw new Error('The clean builds used different recipes or produced different outputs.');
}
const build = JSON.parse(metadata);
const inventory = JSON.parse(readFileSync(join(first, 'components.json'), 'utf8'));
const minor = build.target;
const php = build.php;
if (!/^8\.[345]$/.test(minor) || !php.startsWith(minor + '.') || php !== version.split('-')[0] ||
	php !== inventory.php.version || build.schema !== 1 || !build.recipe['compiler.lock.json']) {
	throw new Error('The package version, compiler target and source inventory must identify the same PHP build.');
}
const name = '@atomsphp/php-wasm-' + minor.replace('.', '-');
const pin = { package: name, version };
const prefix = 'php_' + minor.replace('.', '_');
const payload = { wasm: prefix + '.wasm', glue: prefix + '.js' };
for (const [file, sha256] of Object.entries(build.recipe)) {
	if (inventory.sourceFiles['php-wasm-build/' + file] !== sha256) {
		throw new Error(`Corresponding source differs from the build for ${file}.`);
	}
}
const destination = resolve(dest);
mkdirSync(destination, { recursive: true });
const stage = mkdtempSync(join(destination, '.pack-'));
try {
	for (const [key, path] of Object.entries(payload)) {
		const bytes = readFileSync(join(first, path));
		pin[key] = { path, bytes: bytes.length, sha256: createHash('sha256').update(bytes).digest('hex') };
		if (pin[key].sha256 !== build.outputs[path]) throw new Error(`Build record differs for ${path}.`);
		writeFileSync(join(stage, path), bytes);
	}
	for (const file of ['corresponding-source.tar.gz', 'THIRD_PARTY_NOTICES.txt', 'components.json']) {
		copyFileSync(join(first, file), join(stage, file));
	}
	writeFileSync(join(stage, 'build-metadata.json'), metadata);
	const sourceVerifier = fileURLToPath(new URL('./source-bundle.py', import.meta.url));
	execFileSync('python3', ['-B', sourceVerifier, 'verify', stage], { stdio: 'inherit' });
	const files = [...Object.values(payload), 'build-metadata.json', 'corresponding-source.tar.gz', 'THIRD_PARTY_NOTICES.txt', 'components.json'];
	const manifest = {
		name, version, type: 'module', license: 'SEE LICENSE IN THIRD_PARTY_NOTICES.txt', files,
		repository: { type: 'git', url: 'git+https://github.com/AtomsPHP/atoms.git', directory: 'cloudflare/php-wasm-build' },
		publishConfig: { access: 'public', provenance: true },
	};
	writeFileSync(join(stage, 'package.json'), JSON.stringify(manifest, null, 2) + '\n');
	const result = JSON.parse(execFileSync('npm', ['pack', '--json', '--ignore-scripts'], {
		cwd: stage, encoding: 'utf8',
	}))[0];
	const expectedFiles = [...files, 'package.json'].sort();
	const packedFiles = result.files.map(file => file.path).sort();
	if (JSON.stringify(packedFiles) !== JSON.stringify(expectedFiles)) {
		throw new Error('The interpreter tarball differs from the release file list.');
	}
	for (const { path, bytes, sha256 } of [pin.wasm, pin.glue]) {
		const packed = execFileSync('tar', ['-xOzf', join(stage, result.filename), 'package/' + path], {
			maxBuffer: bytes + 1,
		});
		if (packed.length !== bytes || createHash('sha256').update(packed).digest('hex') !== sha256) {
			throw new Error(`Packed ${path} differs from the clean builds.`);
		}
	}
	const archive = readFileSync(join(stage, result.filename));
	pin.sha256 = createHash('sha256').update(archive).digest('hex');
	pin.integrity = 'sha512-' + createHash('sha512').update(archive).digest('base64');
	pin.packedBytes = archive.length;
	pin.unpackedBytes = result.unpackedSize;
	if (result.integrity !== pin.integrity) throw new Error('npm pack reported a different integrity.');
	writeFileSync(join(stage, 'interpreter.json'), JSON.stringify(pin, null, 2) + '\n');
	renameSync(join(stage, result.filename), join(destination, result.filename));
	renameSync(join(stage, 'interpreter.json'), join(destination, 'interpreter.json'));
	console.log(JSON.stringify({ filename: result.filename, integrity: result.integrity, pin }, null, 2));
} finally {
	rmSync(stage, { recursive: true, force: true });
}
