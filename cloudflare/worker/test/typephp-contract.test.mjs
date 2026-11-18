import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { phpTarget, targetManifest } from '../scripts/lib/php-target.mjs';
import { phpContract } from './php-contract.mjs';
import { renderMatrixDoc } from '../scripts/gen-pdo-matrix.mjs';

test('packaged targets are a verbatim copy of the CLI authority', () => {
    assert.deepEqual(readFileSync(new URL('../scripts/lib/php-targets.json', import.meta.url)),
        readFileSync(new URL('../../../packages/cli/resources/php-targets.json', import.meta.url)));
    assert.equal(phpTarget('php-wasm').php_version, '8.3.32');
    assert.equal(phpTarget('typephp').php_version, '8.5.9');
});

test('staging refuses to relabel source archives, including patch mismatches', () => {
    assert.throws(() => targetManifest({ abi: { php: '8.3' } }, 'typephp'), /Rebuild for PHP 8.5/);
    assert.throws(() => targetManifest({ abi: { php: '8.5' } }, 'php-wasm'), /Rebuild for PHP 8.3/);
    assert.throws(() => targetManifest({ abi: { php: '8.5', php_version: '8.5.8' } }, 'typephp'), /8.5.9/);
    assert.throws(() => targetManifest({}, 'typephp'), /Rebuild/);
    assert.equal(targetManifest({ abi: { php: '8.5' } }, 'typephp').abi.php_version, '8.5.9');
});

test('conformance profiles use distinct reviewed pins and reports', () => {
    const standard = phpContract();
    const typephp = phpContract('typephp');
    for (const field of ['pins', 'report', 'doc']) assert.notEqual(standard[field], typephp[field]);
    assert.throws(() => phpContract('observed'), /Unknown compiler/);
    assert.throws(() => renderMatrixDoc({ php: '8.5.9', cases: [{ id: 'unreviewed', class: 'deviation' }] }, { cases: {} }, 'typephp'), /reviewed pin/);
    assert.throws(() => renderMatrixDoc({ php: '8.5.9', cases: [{ id: 'broken', class: 'error' }] },
        { cases: { broken: { class: 'error', why: 'Cannot accept a broken comparator' } } }, 'typephp'), /Invalid/);
});
