#!/usr/bin/env node

// Real application HTTP → Atom → signed Laravel callback, entirely on loopback.
import assert from 'node:assert/strict';
import { spawn, spawnSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { cpSync, existsSync, mkdtempSync, readFileSync, rmSync, symlinkSync, writeFileSync } from 'node:fs';
import { createServer } from 'node:net';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { setTimeout as delay } from 'node:timers/promises';
import { fileURLToPath } from 'node:url';
import { stageRuntimePackage } from '../../cloudflare/worker/scripts/pack-runtime.mjs';

const repo = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const workerSource = join(repo, 'cloudflare/worker');
const settings = {
    startupMs: Number(process.env.ATOMS_SMOKE_STARTUP_MS ?? 120000),
    requestMs: Number(process.env.ATOMS_SMOKE_REQUEST_MS ?? 30000),
    pollMs: Number(process.env.ATOMS_SMOKE_POLL_MS ?? 250),
};
assert.notEqual(process.platform, 'win32', 'Run this concurrent PHP CLI smoke on Linux, macOS, or WSL2.');
assert.ok(existsSync(join(repo, 'vendor/autoload.php')), 'Run composer install at the repository root first.');
assert.ok(existsSync(join(workerSource, 'node_modules/wrangler')), 'Run npm ci in cloudflare/worker first.');

const temporary = mkdtempSync(join(tmpdir(), 'atoms-laravel-callback-'));
const app = join(temporary, 'app');
const processes = [];
// Do not inherit a developer's deployment/configuration inputs into the fixture.
const env = Object.fromEntries(Object.entries(process.env).filter(([key]) =>
    !/^(ATOMS_|CLOUDFLARE_|CF_|APP_|LARAVEL_|PHP_CLI_SERVER_WORKERS$)/.test(key)));
env.CI = 'true';
env.WRANGLER_SEND_METRICS = 'false';

function run(command, args, cwd) {
    const result = spawnSync(command, args, { cwd, env, encoding: 'utf8', timeout: settings.startupMs });
    assert.equal(result.status, 0, `${command} failed: ${result.error ?? ''}\n${result.stdout}\n${result.stderr}`);
}

function start(name, command, args, cwd, overrides = {}) {
    const child = spawn(command, args, { cwd, env: { ...env, ...overrides }, detached: true, stdio: ['ignore', 'pipe', 'pipe'] });
    const entry = { name, child, output: '', error: null };
    child.stdout.on('data', (data) => { entry.output += data; });
    child.stderr.on('data', (data) => { entry.output += data; });
    child.on('error', (error) => { entry.error = error; });
    processes.push(entry);
    return entry;
}

async function freePort() {
    const server = createServer();
    await new Promise((resolve, reject) => { server.once('error', reject); server.listen(0, '127.0.0.1', resolve); });
    const port = server.address().port;
    await new Promise((resolve) => server.close(resolve));
    return port;
}

async function ready(url, entry) {
    const deadline = Date.now() + settings.startupMs;
    while (Date.now() < deadline) {
        assert.equal(entry.error, null, `${entry.name}: ${entry.error}`);
        assert.equal(entry.child.exitCode, null, `${entry.name} exited before readiness`);
        try {
            const response = await fetch(url, { signal: AbortSignal.timeout(settings.pollMs) });
            await response.arrayBuffer();
            if (response.ok) return;
        } catch { /* Startup has not finished. */ }
        await delay(settings.pollMs);
    }
    throw new Error(`${entry.name} did not become ready: ${url}`);
}

async function stop() {
    // Include Artisan's forked PHP workers and the CLI's Wrangler/workerd children.
    for (const { child } of processes) {
        if (child.pid) {
            try { process.kill(-child.pid, 'SIGTERM'); } catch (error) { if (error.code !== 'ESRCH') throw error; }
        }
    }
    await delay(settings.pollMs);
    for (const { child } of processes) {
        if (child.pid) {
            try { process.kill(-child.pid, 'SIGKILL'); } catch (error) { if (error.code !== 'ESRCH') throw error; }
        }
    }
}
for (const signal of ['SIGINT', 'SIGTERM']) {
    process.once(signal, async () => { await stop(); rmSync(temporary, { recursive: true, force: true }); process.exit(1); });
}

try {
    cpSync(join(repo, 'examples/laravel'), app, {
        recursive: true,
        filter: (source) => !['vendor', '.env', '.atoms', 'atoms-worker'].includes(source.split('/').at(-1)),
    });
    symlinkSync(join(repo, 'vendor'), join(app, 'vendor'), 'dir');
    // Reuse installed monorepo packages, but load App classes from the copied app.
    for (const [file, prefix] of [['artisan', ''], ['public/index.php', '../']]) {
        const path = join(app, file);
        const original = `require __DIR__ . '/${prefix}vendor/autoload.php';`;
        const source = readFileSync(path, 'utf8');
        assert.ok(source.includes(original), `Missing autoload anchor in ${file}`);
        writeFileSync(path, source.replace(original,
            `$loader = require __DIR__ . '/${prefix}vendor/autoload.php';\n$loader->setPsr4('App\\\\', __DIR__ . '/${prefix}app/');`));
    }
    const appPort = await freePort();
    let workerPort = await freePort();
    while (workerPort === appPort) workerPort = await freePort();
    const label = `callback-${randomUUID()}`;
    writeFileSync(join(app, '.env'), `APP_NAME=${label}\nAPP_ENV=local\nAPP_DEBUG=false\nATOMS_ENDPOINT=http://127.0.0.1:${workerPort}\n`);

    const packageRoot = join(temporary, 'runtime');
    stageRuntimePackage(packageRoot);
    run(process.execPath, [join(packageRoot, 'bin/atoms-runtime-cloudflare.mjs'), 'init', join(app, 'atoms-worker')], app);
    symlinkSync(join(workerSource, 'node_modules'), join(app, 'atoms-worker/node_modules'), 'dir');
    run(process.execPath, ['scripts/prepare-runtime.mjs'], join(app, 'atoms-worker'));

    const worker = start('Worker', 'php', [join(repo, 'packages/cli/bin/atoms'), 'dev', '--env', 'production',
        '--port', String(workerPort), '--callback-url', `http://127.0.0.1:${appPort}/atoms/callback`], app);
    await ready(`http://127.0.0.1:${workerPort}/healthz`, worker);
    const laravel = start('Laravel', 'php', ['artisan', 'serve', '--host=127.0.0.1', `--port=${appPort}`, '--no-reload'], app,
        { PHP_CLI_SERVER_WORKERS: '4' });
    await ready(`http://127.0.0.1:${appPort}/up`, laravel);

    const began = performance.now();
    const response = await fetch(`http://127.0.0.1:${appPort}/api/callback-check`, {
        headers: { Accept: 'application/json' }, signal: AbortSignal.timeout(settings.requestMs),
    });
    const body = await response.text();
    assert.equal(response.status, 200, `Application HTTP response: ${body}`);
    assert.deepEqual(JSON.parse(body), { application: label });
    console.log(`Application HTTP → Atom → Laravel callback: HTTP 200 in ${Math.round(performance.now() - began)} ms.`);
} catch (error) {
    for (const entry of processes) console.error(`${entry.name}:\n${entry.output}`);
    const log = join(app, 'storage/logs/laravel.log');
    if (existsSync(log)) console.error(readFileSync(log, 'utf8'));
    throw error;
} finally {
    await stop();
    rmSync(temporary, { recursive: true, force: true });
}
