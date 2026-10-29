import test from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { copyFileSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const source = readFileSync(new URL('./conformance.mjs', import.meta.url), 'utf8');

// Exercise the actual runner in isolation from a developer's credentials.
// An invalid base URL makes any selected request throw before network I/O.
function run({ mutation = '', only = '1', skip = '' } = {}) {
    const directory = mkdtempSync(join(tmpdir(), 'atoms-check-registry-'));
    try {
        mkdirSync(join(directory, 'test'));
        mkdirSync(join(directory, 'scripts'));
        copyFileSync(new URL('./required-capabilities.mjs', import.meta.url),
            join(directory, 'test', 'required-capabilities.mjs'));
        copyFileSync(new URL('../scripts/gen-pdo-matrix.mjs', import.meta.url),
            join(directory, 'scripts', 'gen-pdo-matrix.mjs'));
        const runner = join(directory, 'test', 'conformance.mjs');
        // Mutate the completed registry, before its startup validation.
        writeFileSync(runner, source.replace('async function run() {', `${mutation}\nasync function run() {`));
        const child = spawnSync(process.execPath, [runner], {
            encoding: 'utf8',
            timeout: 10000,
            env: { ATOMS_BASE_URL: 'not-a-url', ATOMS_BEARER_AUTH: 'disabled',
                ATOMS_ONLY: only, ATOMS_SKIP: skip },
        });
        assert.ifError(child.error);
        return { code: child.status, output: child.stdout + child.stderr };
    } finally {
        rmSync(directory, { recursive: true, force: true });
    }
}

for (const [name, mutation] of [
    ['deleted check', 'checks.splice(1, 1);'],
    ['duplicate number', 'checks[1].num = checks[0].num;'],
    ['gap', 'checks[1].num = 99;'],
    ['reordered checks', '[checks[0], checks[1]] = [checks[1], checks[0]];'],
]) {
    test(`${name} fails at startup even when excluded by ATOMS_ONLY`, () => {
        const result = run({ mutation });
        assert.equal(result.code, 1, result.output);
        assert.match(result.output, /Conformance check numbers must be exactly 1\.\.N in registration order/);
        assert.doesNotMatch(result.output, /Callback listener:|CHECK \d+:|Results:/);
    });
}

test('unexpected failures use the selected record number and name', () => {
    const result = run({ only: '2' });
    assert.equal(result.code, 1, result.output);
    assert.match(result.output, /✗ CHECK 2: invoke \+ result envelope — TypeError: Invalid URL/);
    assert.doesNotMatch(result.output, /✗ CHECK (?:1|3):|\(unknown\)/);
    assert.match(result.output, /Results: 0 passed, 1 failed/);
});

test('ATOMS_SKIP takes precedence over ATOMS_ONLY for a registered check', () => {
    const result = run({ only: '2', skip: '2' });
    assert.equal(result.code, 0, result.output);
    assert.match(result.output, /⊘ CHECK 2: skipped\n/);
    assert.doesNotMatch(result.output, /✗ CHECK/);
    assert.match(result.output, /Results: 0 passed, 0 failed/);
});
