import test from 'node:test';
import assert from 'node:assert/strict';
import { existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { CORE_GUEST_OMISSIONS, coreSourceEntries, runtimeFiles } from '../scripts/lib/bundle-module.mjs';

const workerRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const packagesCoreRoot = resolve(workerRoot, '../../packages/core');

test('CORE_GUEST_OMISSIONS explicitly omits Sqlite/SqliteDatabase.php', () => {
	assert.deepEqual([...CORE_GUEST_OMISSIONS], ['Sqlite/SqliteDatabase.php']);
	assert.ok(
		existsSync(join(packagesCoreRoot, 'src', 'Sqlite', 'SqliteDatabase.php')),
		'anti-vacuous: packages/core/src/Sqlite/SqliteDatabase.php must exist in the monorepo',
	);
});

test('coreSourceEntries reads packages/core/src minus omissions plus resources/errors.json', () => {
	const entries = coreSourceEntries(packagesCoreRoot);
	const rels = entries.map((entry) => entry.rel);

	assert.ok(rels.includes('Atom.php'));
	assert.ok(rels.includes('Errors/ErrorCode.php'));
	assert.ok(rels.includes('resources/errors.json'));
	assert.ok(!rels.includes('Sqlite/SqliteDatabase.php'));
	assert.deepEqual(
		rels,
		[...rels].sort((a, b) => (a < b ? -1 : a > b ? 1 : 0)),
		'entries must be deterministically sorted',
	);
});

test('coreSourceEntries throws loudly when src/ or resources/errors.json is missing', () => {
	const dir = mkdtempSync(join(tmpdir(), 'atoms-core-entries-'));
	try {
		assert.throws(() => coreSourceEntries(dir), /packages\/core\/src\/ not found/);

		mkdirSync(join(dir, 'src'), { recursive: true });
		assert.throws(() => coreSourceEntries(dir), /packages\/core\/resources\/errors\.json not found/);

		mkdirSync(join(dir, 'resources'), { recursive: true });
		writeFileSync(join(dir, 'resources', 'errors.json'), '{}');
		assert.throws(() => coreSourceEntries(dir), /missing Atom\.php/);
	} finally {
		rmSync(dir, { recursive: true, force: true });
	}
});

test('runtimeFiles falls back to ../../packages/core inside the monorepo', () => {
	assert.ok(
		!existsSync(join(workerRoot, 'php', 'atoms-core')),
		'monorepo worker should not carry a committed php/atoms-core/ duplicate',
	);

	const files = runtimeFiles(workerRoot);

	assert.ok('/atoms/runtime/bootstrap.php' in files);
	assert.equal(
		files['/atoms/core/src/Atom.php'],
		readFileSync(join(packagesCoreRoot, 'src', 'Atom.php'), 'utf8'),
	);
	assert.equal(
		files['/atoms/core/resources/errors.json'],
		readFileSync(join(packagesCoreRoot, 'resources', 'errors.json'), 'utf8'),
	);
	assert.ok(!('/atoms/core/src/Sqlite/SqliteDatabase.php' in files));
});

test('runtimeFiles prefers php/atoms-core/ when present and fails loud when missing or incomplete', () => {
	const tempRoot = mkdtempSync(join(tmpdir(), 'atoms-runtime-files-'));
	try {
		// Standalone directory layout with no sibling packages/core/.
		const workerDir = join(tempRoot, 'a', 'b');
		mkdirSync(join(workerDir, 'php', 'runtime'), { recursive: true });
		writeFileSync(join(workerDir, 'php', 'runtime', 'bootstrap.php'), '<?php // runtime\n');

		assert.throws(
			() => runtimeFiles(workerDir),
			/atoms\/core not found/,
			'must throw when neither php/atoms-core/ nor ../../packages/core/ exists',
		);

		// Incomplete php/atoms-core/ must fail loud rather than falling back silently.
		mkdirSync(join(workerDir, 'php', 'atoms-core'), { recursive: true });
		assert.throws(
			() => runtimeFiles(workerDir),
			/php\/atoms-core\/.*incomplete/,
		);

		// Populate staged php/atoms-core/ alongside a conflicting ../../packages/core/
		// to verify php/atoms-core/ takes precedence and still filters omissions/non-code.
		mkdirSync(join(workerDir, 'php', 'atoms-core', 'resources'), { recursive: true });
		mkdirSync(join(workerDir, 'php', 'atoms-core', 'Sqlite'), { recursive: true });
		writeFileSync(join(workerDir, 'php', 'atoms-core', 'Atom.php'), '<?php // staged\n');
		writeFileSync(join(workerDir, 'php', 'atoms-core', 'resources', 'errors.json'), '{"staged":true}');
		writeFileSync(join(workerDir, 'php', 'atoms-core', 'Sqlite', 'SqliteDatabase.php'), '<?php // omitted\n');
		writeFileSync(join(workerDir, 'php', 'atoms-core', 'NOTES.md'), '# ignored\n');

		mkdirSync(join(tempRoot, 'packages', 'core', 'src'), { recursive: true });
		mkdirSync(join(tempRoot, 'packages', 'core', 'resources'), { recursive: true });
		writeFileSync(join(tempRoot, 'packages', 'core', 'src', 'Atom.php'), '<?php // monorepo\n');
		writeFileSync(join(tempRoot, 'packages', 'core', 'resources', 'errors.json'), '{"monorepo":true}');

		const staged = runtimeFiles(workerDir);
		assert.equal(staged['/atoms/core/src/Atom.php'], '<?php // staged\n');
		assert.equal(staged['/atoms/core/resources/errors.json'], '{"staged":true}');
		assert.ok(!('/atoms/core/src/Sqlite/SqliteDatabase.php' in staged));
		assert.ok(!('/atoms/core/src/NOTES.md' in staged));

		// Removing php/atoms-core/ falls back to ../../packages/core/.
		rmSync(join(workerDir, 'php', 'atoms-core'), { recursive: true, force: true });
		const fallback = runtimeFiles(workerDir);
		assert.equal(fallback['/atoms/core/src/Atom.php'], '<?php // monorepo\n');
		assert.equal(fallback['/atoms/core/resources/errors.json'], '{"monorepo":true}');

		// Missing php/runtime/ fails loud.
		rmSync(join(workerDir, 'php', 'runtime'), { recursive: true, force: true });
		assert.throws(() => runtimeFiles(workerDir), /php\/runtime\/ not found/);
	} finally {
		rmSync(tempRoot, { recursive: true, force: true });
	}
});
