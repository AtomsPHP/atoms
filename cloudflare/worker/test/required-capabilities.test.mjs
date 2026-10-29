import test, { after } from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { copyFileSync, mkdirSync, mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { REQUIRED_CAPABILITIES, isRequired } from './required-capabilities.mjs';

// Isolate the runner from a developer's optional test/.dev-secret.json.
const directory = mkdtempSync(join(tmpdir(), 'atoms-capabilities-'));
mkdirSync(join(directory, 'test'));
mkdirSync(join(directory, 'scripts'));
mkdirSync(join(directory, 'empty-bin'));
for (const name of ['conformance.mjs', 'required-capabilities.mjs']) {
    copyFileSync(fileURLToPath(new URL(`./${name}`, import.meta.url)), join(directory, 'test', name));
}
copyFileSync(fileURLToPath(new URL('../scripts/gen-pdo-matrix.mjs', import.meta.url)),
    join(directory, 'scripts', 'gen-pdo-matrix.mjs'));
const runner = join(directory, 'test', 'conformance.mjs');
after(() => rmSync(directory, { recursive: true, force: true }));
const secret = Buffer.alloc(32, 1).toString('base64');
const previous = Buffer.alloc(32, 2).toString('base64');

async function run(check, env = {}) {
    const server = createServer((req, res) => {
        if (req.url.startsWith('/ws/')) {
            res.writeHead(401, { 'content-type': 'application/json' });
            res.end(JSON.stringify({ error: { code: 'ticket_invalid' } }));
        } else {
            res.writeHead(200, { 'content-type': 'application/json' });
            res.end(JSON.stringify({ result: {} }));
        }
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    try {
        const child = spawn(process.execPath, [runner], { env: {
            PATH: join(directory, 'empty-bin'), ATOMS_BASE_URL: `http://127.0.0.1:${server.address().port}`,
            ATOMS_ONLY: String(check), ATOMS_BEARER_AUTH: 'disabled', ...env,
        } });
        let output = '';
        child.stdout.on('data', data => output += data);
        child.stderr.on('data', data => output += data);
        const code = await new Promise((resolve, reject) => {
            child.on('error', reject);
            child.on('close', resolve);
        });
        return { code, output };
    } finally {
        await new Promise(resolve => server.close(resolve));
    }
}

const cases = [
    ['callback', 'ATOMS_REQUIRE_CALLBACK_CHECKS', 13, 'no callback listener'],
    ['sqlCaps', 'ATOMS_REQUIRE_SQL_CAP_CHECKS', 29, 'ATOMS_SQL_MAX_ROWS/ATOMS_SQL_MAX_RESULT_BYTES'],
    ['tickets', 'ATOMS_REQUIRE_TICKET_CHECKS', 31, 'issuing a ticket needs the root'],
    ['bearerVector', 'ATOMS_REQUIRE_BEARER_VECTOR', 39, 'no `php` on PATH'],
    ['rotation', 'ATOMS_REQUIRE_ROTATION_CHECKS', 40, 'rotation overlap secret'],
    ['deny', 'ATOMS_REQUIRE_DENY_CHECKS', 42, 'control key ATOMS_DEBUG_ENDPOINTS did not resolve'],
];

test('the capability matrix contains exactly the six supported flags', () => {
    assert.deepEqual(REQUIRED_CAPABILITIES, Object.fromEntries(cases.map(([key, flag]) => [key, flag])));
    for (const [key, flag] of cases) {
        for (const value of ['1', 'true', 'YES', 'On']) assert.equal(isRequired(key, { [flag]: value }), true);
        for (const value of ['', '0', 'false', ' true ']) assert.equal(isRequired(key, { [flag]: value }), false);
    }
    assert.throws(() => isRequired('unknown', {}), /Unknown required capability/);
});

for (const [key, flag, check, reason] of cases) {
    test(`${key}: absent prerequisite skips optionally and fails when required`, async () => {
        const optional = await run(check);
        assert.equal(optional.code, 0, optional.output);
        assert.ok(optional.output.includes(reason), optional.output);
        assert.match(optional.output, new RegExp(`(?:⊘|✓) CHECK ${check}:`));
        const required = await run(check, { [flag]: 'yes' });
        assert.equal(required.code, 1, required.output);
        assert.ok(required.output.includes(reason), required.output);
        assert.match(required.output, new RegExp(`✗ CHECK ${check}:`));
    });
}

test('ticket partial legs cannot silently skip when required', async () => {
    const optional = await run(33);
    assert.equal(optional.code, 0, optional.output);
    assert.match(optional.output, /scope, expiry and unsigned-form legs skipped/);
    const required = await run(33, { ATOMS_REQUIRE_TICKET_CHECKS: '1' });
    assert.equal(required.code, 1, required.output);
    assert.match(required.output, /scope, expiry and unsigned-form legs need ATOMS_SHARED_SECRET/);
});

test('rotation callback leg reports a required missing listener', async () => {
    const env = { ATOMS_SHARED_SECRET: secret, ATOMS_SHARED_SECRET_PREVIOUS: previous };
    const optional = await run(40, env);
    assert.doesNotMatch(optional.output, /callback leg needs a listener/);
    const required = await run(40, { ...env, ATOMS_REQUIRE_CALLBACK_CHECKS: '1' });
    assert.equal(required.code, 1, required.output);
    assert.match(required.output, /callback leg needs a listener/);
});
