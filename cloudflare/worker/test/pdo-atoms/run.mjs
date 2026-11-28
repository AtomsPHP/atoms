#!/usr/bin/env node
import assert from 'node:assert/strict';
import { spawn, spawnSync } from 'node:child_process';
import { createHash, randomBytes } from 'node:crypto';
import { once } from 'node:events';
import { appendFileSync, copyFileSync, cpSync, mkdirSync, mkdtempSync, readFileSync, readdirSync, rmSync, symlinkSync, writeFileSync } from 'node:fs';
import { createServer } from 'node:net';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { setTimeout as delay } from 'node:timers/promises';
import { fileURLToPath } from 'node:url';
import { parseArgs } from 'node:util';

const here = dirname(fileURLToPath(import.meta.url));
const worker = resolve(here, '../..');
const repo = resolve(worker, '../..');
const {positionals, values} = parseArgs({allowPositionals: true, options: {embed: {type: 'string'}, php: {type: 'string'}}});
const [scaffoldArg, yiiArg] = positionals;
if (!scaffoldArg || positionals.length > 2 || (values.php && (!values.embed || !['8.3', '8.4', '8.5'].includes(values.php)))) {
    throw new Error('Usage: node run.mjs [--embed build [--php 8.3|8.4|8.5]] <installed-scaffold> [yii2-checkout]');
}
const embed = values.embed && resolve(values.embed);
const scaffold = resolve(scaffoldArg);
const yii = yiiArg && resolve(yiiArg);
const env = { ...process.env, COMPOSER_DISABLE_NETWORK: '1', WRANGLER_SEND_METRICS: 'false', NO_COLOR: '1' };
const scratch = mkdtempSync(join(tmpdir(), 'pdo-atoms-'));

function run(cmd, args, cwd = scratch) {
    const result = spawnSync(cmd, args, { cwd, env, encoding: 'utf8' });
    if (result.error) throw result.error;
    if (result.status !== 0) throw new Error([cmd, ...args].join(' ') + '\n' + result.stdout + result.stderr);
    return result.stdout.trim();
}
const readJson = path => JSON.parse(readFileSync(path, 'utf8'));
const writeJson = (path, data) => writeFileSync(path, JSON.stringify(data, null, 2) + '\n');
const sha256 = path => createHash('sha256').update(readFileSync(path)).digest('hex');

function prepareYii() {
    const commit = '8a7f2effa34448e6c3f412c847409fa844cf3e18';
    assert.equal(run('git', ['rev-parse', 'HEAD'], yii), commit, 'Yii 2.0.53 source commit');
    assert.equal(run('git', ['status', '--porcelain', '--', 'framework'], yii), '', 'Yii framework source must match the pinned tree');
    const framework = join(yii, 'framework');
    const files = run('php', [join(here, 'yii-files.php'), framework, join(scratch, 'sqlite-control.db')]).split('\n');
    const dependency = join(scratch, 'yii-db');
    for (const name of [...files, 'LICENSE.md']) {
        const dest = join(dependency, name);
        mkdirSync(dirname(dest), { recursive: true });
        copyFileSync(join(framework, name), dest);
    }
    writeJson(join(dependency, 'composer.json'), {
        name: 'yiisoft/yii2', version: '2.0.53', autoload: { files: ['Yii.php'] },
    });

    // Approve only this fixture's offline dependency in a private copy of the
    // CLI resource. The normal build still resolves, validates and packages it.
    const cli = join(repo, 'packages/cli');
    const resource = 'resources/allowed-packages.json';
    const allow = readJson(join(cli, resource));
    allow.packages.push({ name: 'yiisoft/yii2', namespaces: ['yii\\'] });
    mkdirSync(join(scratch, 'resources'));
    writeJson(join(scratch, resource), allow);
    const config = join(scratch, 'src/Config/AllowedPackages.php');
    mkdirSync(dirname(config), { recursive: true });
    copyFileSync(join(cli, 'src/Config/AllowedPackages.php'), config);
    return config;
}

async function reservePort() {
    const socket = createServer();
    socket.listen(0, '127.0.0.1');
    await once(socket, 'listening');
    const { port } = socket.address();
    await new Promise(resolveClose => socket.close(resolveClose));
    return port;
}

function checkSqlErrors(modes) {
    const cleared = ['00000', null, null];
    const sqlstates = {};
    for (const mode of ['silent', 'warning', 'exception']) {
        const { exec, query, execute, resets, connectionAfterStatementError } = modes[mode];
        sqlstates[mode] = {};
        for (const [name, failure] of Object.entries({ exec, query, execute })) {
            const state = name === 'execute' ? '23000' : 'HY000';
            const detail = name === 'execute' ? /probe_rows[.]key/i : /missing_probe_table/i;
            assert.equal(failure.code, state);
            assert.equal(failure.info.length, 3);
            assert.equal(failure.info[0], state);
            assert.equal(failure.info[1], 1);
            assert.match(failure.info[2], detail);
            assert.equal(failure.result, mode === 'exception' ? null : false);
            assert.equal(failure.warnings.length, mode === 'warning' ? 1 : 0);
            if (mode === 'warning') {
                const [[level, message]] = failure.warnings;
                assert.equal(level, 2); // E_WARNING in PHP
                assert.ok(message.includes('SQLSTATE[' + state + ']'));
                assert.match(message, detail);
            }
            if (mode === 'exception') {
                assert.equal(failure.exception.code, state);
                assert.deepEqual(failure.exception.info, failure.info);
                assert.match(failure.exception.message, detail);
            } else {
                assert.equal(failure.exception, null);
            }
            sqlstates[mode][name] = failure.code;
        }
        assert.deepEqual(connectionAfterStatementError, cleared);
        assert.deepEqual(resets, [cleared, cleared, cleared]);
    }
    return sqlstates;
}

async function exercise(url, version) {
    async function invoke(method, expectedStatus = 200, { atom = 'PdoProbe', id = 'test', args = [] } = {}) {
        const response = await fetch(url + '/invoke/' + atom + '/' + id + '/' + method, {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ args }), signal: AbortSignal.timeout(30000),
        });
        const body = await response.json();
        assert.equal(response.status, expectedStatus, method + ': ' + JSON.stringify(body));
        return expectedStatus === 200 ? body.result : body.error;
    }
    const registration = await invoke('registration');
    assert.equal(registration.php, version);
    assert.ok(registration.drivers.includes('atoms'));
    assert.equal(registration.turn, 'atoms');
    assert.equal(registration.activation, 'atoms');
    assert.equal(registration.activationCursorClosed, true);
    assert.equal(registration.beforeActivationRejected, true);
    assert.deepEqual(registration.invalidRejected, [true, true, true, true, true, true]);

    const namedArguments = await invoke('namedArguments');
    // PHP 8.4 added PDO::connect(). Every handle must honor the named options.
    const factories = version.startsWith('8.3.') ? 2 : 4;
    assert.deepEqual(namedArguments, Array.from({ length: factories }, () => ['atoms', 1]));

    const quotedValues = await invoke('quotedValues');
    assert.deepEqual(quotedValues, {
        'pdo.quote.plain': ["'hello'", 'hello'],
        'pdo.quote.apostrophe': ["'O''Reilly'", "O'Reilly"],
        'pdo.quote.empty': ["''", ''],
        'pdo.quote.param_int': ["'42'", '42'],
        'pdo.quote.param_bool': 'TypeError',
        'pdo.quote.param_null': 'TypeError',
        'pdo.quote.param_lob': ["'x'", 'x'],
        'pdo.quote.unknown_type': ["'x'", 'x'],
        'pdo.quote.nul_byte': 'PDOException',
        'pdo.quote.utf8': ["'héllo €'", 'héllo €'],
        'pdo.quote.weak_null': ["''", ''],
        'pdo.quote.weak_bool_true': ["'1'", '1'],
        'pdo.quote.weak_int': ["'42'", '42'],
        nulByErrorMode: [true, true, true],
        errorAfterQuote: {
            exec: ['HY000', '00000', ['00000', null, null]],
            query: ['HY000', '00000', ['00000', null, null]],
        },
    });

    assert.equal(await invoke('commitControl'), true);
    const sqlErrors = checkSqlErrors(await invoke('sqlErrors'));
    const committed = await invoke('rows');
    assert.deepEqual(committed, [{ key: 'control', value: 5000000000 }]);
    assert.equal(await invoke('retain'), 'first-turn row');
    const cursor = await invoke('readSavedCursor');
    assert.deepEqual(cursor, {
        unread: [false, false], closedColumnCount: 1, newColumnCount: 2,
        newColumns: { marker: 'another unread row', extra: 42 },
        oldValue: [null, null], oldValueAfterExecute: [null, null],
        newValue: 'second-turn row', savedViewAfterFetch: 'second-turn row',
    });
    const rollback = await invoke('rollbackPending');
    const pending = [{ value: 5000000000 }];
    assert.deepEqual(rollback, {
        beforeDrop: pending, afterDrop: pending, callbackGuard: true, otherOwns: false, ownerOwns: true,
        afterRollback: [], cachedReads: [[0, 'cached'], [1, 'cached']],
    });
    assert.deepEqual(await invoke('rows'), committed);
    let yiiCase;
    if (yii) {
        yiiCase = await invoke('yii', 200, { atom: 'YiiProbe' });
        assert.deepEqual(yiiCase, ['2.0.53', '1', '0', [{ value: 42 }]]);
    }
    assert.deepEqual(await invoke('destroyOwner'), committed);
    assert.deepEqual(await invoke('rows'), committed);
    const abandoned = await invoke('abandon', 500);
    assert.equal(abandoned.code, 'atom_exception');
    assert.match(abandoned.message, /transaction still open/);
    assert.deepEqual(await invoke('rows'), committed);
    const recoveredOwner = await invoke('reuseAbandonedOwner');
    assert.deepEqual(recoveredOwner, {
        ownedAtStart: false, unread: false, nonownerRollbackRejected: true, otherOwns: true,
        reusedOwnerWrite: [{ value: 5000000000 }], rows: committed,
    });

    const objectIdsByAtom = new Map();
    async function cachedValues(id, args = []) {
        const { objectIds, ...read } = await invoke('cachedValues', 200, { atom: yii ? 'YiiProbe' : 'PdoProbe', id, args });
        assert.equal(objectIds.length, yii ? 3 : 1);
        if (objectIdsByAtom.has(id)) {
            assert.deepEqual(objectIds, objectIdsByAtom.get(id), 'saved connection object identity for ' + id);
        } else {
            objectIdsByAtom.set(id, objectIds);
        }
        return read;
    }
    const cache = [
        await cachedValues('test', [71]),
        await cachedValues('second'),
        await cachedValues('second', [82]),
        await cachedValues('test'),
        await cachedValues('second'),
    ];
    const expected = [
        ['test', 1, 71], ['second', 1, false], ['second', 2, 82],
        ['test', 2, 71], ['second', 3, 82],
    ];
    assert.deepEqual(cache, expected.map(([id, read, nativeValue]) => ({
        id, read, nativeValue,
        ...(yii && { samePdo: true, yiiValue: nativeValue === false ? false : String(nativeValue) }),
    })));
    return {
        registration, namedArguments, quotedValues, sqlErrors, committed, rollback, yii: yiiCase,
        abandonment: abandoned.code, recoveredOwner, cursor, cache,
    };
}

async function runWorker(dir, version) {
    const port = await reservePort();
    const url = 'http://127.0.0.1:' + port;
    writeFileSync(join(dir, '.dev.vars'), 'ATOMS_SHARED_SECRET=' + randomBytes(32).toString('base64') + '\n', { mode: 0o600 });
    const wrangler = spawn(process.execPath, [
        join(dir, 'node_modules/wrangler/bin/wrangler.js'), 'dev', '--local',
        '--ip', '127.0.0.1', '--port', String(port), '--inspector-port', '0', '--var', 'ATOMS_BEARER_AUTH:disabled',
    ], { cwd: dir, env, stdio: ['ignore', 'pipe', 'pipe'], detached: true });
    let log = '';
    for (const stream of [wrangler.stdout, wrangler.stderr]) stream.on('data', chunk => { log += chunk; });
    const stopped = once(wrangler, 'exit');
    try {
        const startupDeadline = Date.now() + 60000;
        while (true) {
            if (wrangler.exitCode !== null || wrangler.signalCode) throw new Error('Wrangler exited during startup');
            let ready = false;
            try { ready = (await fetch(url + '/healthz', { signal: AbortSignal.timeout(1000) })).ok; }
            catch { /* workerd is still starting */ }
            if (ready) break;
            if (Date.now() >= startupDeadline) throw new Error('Local Worker startup timed out');
            await delay(100);
        }
        const result = await exercise(url, version);
        // Match the owner's rollback transaction only. Two Atom API reads
        // after the insert surround the unrelated handle's destructor.
        const trace = Array.from(log.matchAll(/PDO_PROBE_TRACE ([a-z.]+ (?:inside|outside))/g), match => match[1]);
        const expected = [
            'resume.tx.begin inside', 'sql.insert inside', 'sql.read inside', 'sql.read inside',
            'resume.tx.rollback outside', 'sql.read outside',
        ];
        const start = trace.findIndex((_, i) => expected.every((event, j) => trace[i + j] === event));
        assert.ok(start >= 0, 'Missing transactionSync trace in ' + JSON.stringify(trace));
        return { ...result, trace: trace.slice(start, start + expected.length) };
    } catch (error) {
        console.error(log);
        throw error;
    } finally {
        if (wrangler.exitCode === null && !wrangler.signalCode) process.kill(-wrangler.pid, 'SIGTERM');
        await stopped;
    }
}

async function runFixture(selection, cliResource) {
    const minor = selection ?? '8.3';
    const pin = readJson(join(repo, 'cloudflare/php-wasm-build/targets', minor, 'candidate.json'));
    const version = pin.version.split('-')[0];
    const scaffoldPackage = readJson(join(scaffold, 'package.json'));
    assert.equal(scaffoldPackage.dependencies[pin.package], pin.version);
    const project = join(scratch, 'project-' + (selection ?? 'default'));
    cpSync(join(here, 'project'), project, { recursive: true });
    for (const file of ['QuoteCases.php', 'CasesWeak.php']) {
        copyFileSync(join(worker, 'fixtures/counter/app/Pdo', file), join(project, 'app/Atoms/PdoProbe/Support', file));
    }
    if (yii) {
        cpSync(join(here, 'yii-project'), project, { recursive: true });
        const atoms = join(project, 'app/Atoms');
        cpSync(join(atoms, 'PdoProbe/migrations'), join(atoms, 'YiiProbe/migrations'), { recursive: true });
    }
    if (selection) {
        const path = join(project, 'atoms.json');
        writeJson(path, { ...readJson(path), php: minor });
    }
    const dir = join(project, 'atoms-worker');
    mkdirSync(dir);
    for (const name of ['src', 'php', 'scripts', 'release', 'wrangler.jsonc', 'package.json']) {
        cpSync(join(scaffold, name), join(dir, name), { recursive: true });
    }
    symlinkSync(join(scaffold, 'node_modules'), join(dir, 'node_modules'), 'dir');
    let privatePair;
    if (embed) {
        const {stagePdoBuild} = await import('../../../php-wasm-build/rust/test/stage-pdo.mjs');
        privatePair = stagePdoBuild(dir, pin, embed);
    } else {
        run(process.execPath, [join(dir, 'scripts/prepare-runtime.mjs')], dir);
    }
    const phpArgs = cliResource ? ['-d', 'auto_prepend_file=' + cliResource] : [];
    run('php', [...phpArgs, join(repo, 'packages/cli/bin/atoms'), 'build'], project);
    const output = join(project, '.atoms/build');
    const manifest = readJson(join(output, 'manifest.json'));
    assert.equal(manifest.toolchain.php, minor);
    const archive = readdirSync(output).find(file => file.endsWith('.tar.gz'));
    run(process.execPath, [join(dir, 'scripts/bundle-from-cli.mjs'), join(output, archive), join(output, 'manifest.json')], dir);

    let wasmSha256, glueSha256;
    if (privatePair) {
        wasmSha256 = sha256(privatePair.wasm);
        glueSha256 = sha256(privatePair.glue);
        assert.equal(wasmSha256, sha256(join(embed, 'php.wasm')));
        assert.equal(glueSha256, sha256(join(embed, 'php.mjs')));
    } else {
        const pair = join(dir, '.php-wasm', pin.wasm.sha256 + '-' + pin.glue.sha256);
        wasmSha256 = sha256(join(pair, pin.wasm.path));
        glueSha256 = sha256(join(pair, pin.glue.path));
        assert.equal(wasmSha256, pin.wasm.sha256);
        assert.equal(glueSha256, pin.glue.sha256);
    }
    copyFileSync(join(here, 'trace.mjs'), join(dir, 'src/pdo-probe-trace.mjs'));
    appendFileSync(join(dir, 'src/bridge.js'), "\nimport { traceTransactions } from './pdo-probe-trace.mjs';\ntraceTransactions(Bridge, TransactionMachine);\n");
    const result = await runWorker(dir, version);
    return { selected: selection ?? 'default', php: version, wasmSha256, glueSha256, ...result };
}

try {
    const cliResource = yii && prepareYii();
    const results = [];
    for (const selection of (embed ? [values.php ?? '8.3'] : [undefined, '8.3', '8.4', '8.5'])) {
        results.push(await runFixture(selection, cliResource));
    }
    console.log(JSON.stringify(results, null, 2));
} finally {
    rmSync(scratch, { recursive: true, force: true });
}
