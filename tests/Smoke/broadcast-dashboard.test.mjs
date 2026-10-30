#!/usr/bin/env node

// Build the guide's exact Atom and subscribe through the real local Worker.
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { once } from 'node:events';
import { mkdtempSync, mkdirSync, readFileSync, readdirSync, rmSync, symlinkSync, writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { stageRuntimePackage } from '../../cloudflare/worker/scripts/pack-runtime.mjs';

const repo = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const workerSource = join(repo, 'cloudflare/worker');
const settings = {
    startupMs: Number(process.env.ATOMS_SMOKE_STARTUP_MS ?? 120000),
    requestMs: Number(process.env.ATOMS_SMOKE_REQUEST_MS ?? 30000),
};
// Only the generated local test secret may configure this Worker.
for (const key of Object.keys(process.env)) {
    if (/^(ATOMS_|CLOUDFLARE_|CF_)/.test(key)) delete process.env[key];
}
process.env.CI = 'true';
process.env.WRANGLER_SEND_METRICS = 'false';
const require = createRequire(join(workerSource, 'package.json'));
const { unstable_dev } = require('wrangler');
const temporary = mkdtempSync(join(tmpdir(), 'atoms-broadcast-dashboard-'));
const originalDirectory = process.cwd();
process.chdir(temporary);
let worker;
let socket;

function run(command, args, cwd, env = process.env) {
    const result = spawnSync(command, args, { cwd, env, encoding: 'utf8', timeout: settings.startupMs });
    assert.equal(result.status, 0, `${command} failed: ${result.error ?? ''}\n${result.stdout}\n${result.stderr}`);
    return result.stdout;
}

try {
    const guide = readFileSync(join(repo, 'docs-site/src/content/docs/guides/websockets-timers.md'), 'utf8');
    const example = /```php title="app\/Atoms\/Dashboard.php"\r?\n([\s\S]*?)\r?\n```/.exec(guide);
    assert.ok(example, 'The guide must contain the complete Dashboard class.');
    assert.doesNotMatch(example[1], /function\s+on(?:Connect|Message|Disconnect)\b/i);
    mkdirSync(join(temporary, 'app/Atoms'), { recursive: true });
    writeFileSync(join(temporary, 'app/Atoms/Dashboard.php'), example[1]);
    writeFileSync(join(temporary, 'atoms.json'), JSON.stringify({
        project: 'broadcast-dashboard',
        paths: { atoms: 'app/Atoms', shared: 'app/Atoms/Shared' },
    }));
    const output = join(temporary, 'build');
    run('php', [join(repo, 'packages/cli/bin/atoms'), 'build', '--fast', '--out', output], temporary);
    const manifestPath = join(output, 'manifest.json');
    const manifest = JSON.parse(readFileSync(manifestPath, 'utf8'));
    assert.equal(manifest.atoms[0].type, 'Dashboard');
    assert.equal(manifest.atoms[0].websocket, true);
    assert.deepEqual(manifest.atoms[0].methods.map((method) => method.name), ['publish']);

    const packageRoot = join(temporary, 'runtime');
    stageRuntimePackage(packageRoot);
    const runtime = join(packageRoot, 'template');
    symlinkSync(join(workerSource, 'node_modules'), join(runtime, 'node_modules'), 'dir');
    run(process.execPath, ['scripts/prepare-runtime.mjs'], runtime);
    const bundle = readdirSync(output).find((name) => name.endsWith('.tar.gz'));
    assert.ok(bundle, 'The CLI must produce a bundle.');
    run(process.execPath, ['scripts/bundle-from-cli.mjs', join(output, bundle), manifestPath], runtime);

    const secret = randomBytes(32).toString('base64');
    writeFileSync(join(runtime, '.dev.vars'), `ATOMS_SHARED_SECRET=${secret}\n`);
    worker = await unstable_dev(join(runtime, 'src/index.js'), {
        config: join(runtime, 'wrangler.jsonc'),
        local: true, ip: '127.0.0.1', port: 0, inspectorPort: 0, persist: false,
        logLevel: 'error',
        experimental: { disableExperimentalWarning: true, disableDevRegistry: true, watch: false },
    });
    const endpoint = `http://${worker.address}:${worker.port}`;
    // Issue the same PHP ticket and bearer a customer application uses.
    const credentials = JSON.parse(run('php', ['-r', String.raw`
        require $argv[1];
        $config = new \Atoms\Client\AtomsConfig($argv[2], getenv('ATOMS_SHARED_SECRET'));
        $issuer = new \Atoms\Client\Tickets\TicketIssuer($config);
        echo json_encode([
            'ticket' => (string) $issuer->issue('Dashboard', 'dashboard'),
            'bearer' => $config->bearerToken(),
        ], JSON_THROW_ON_ERROR);
    `, join(repo, 'vendor/autoload.php'), endpoint], temporary, { ...process.env, ATOMS_SHARED_SECRET: secret }));
    const url = new URL('/ws/Dashboard/dashboard', endpoint);
    url.protocol = 'ws:';
    url.searchParams.set('channels', 'all');
    url.searchParams.set('ticket', credentials.ticket);
    socket = new WebSocket(url);
    await once(socket, 'open', { signal: AbortSignal.timeout(settings.requestMs) });

    // Start listening before the RPC: the broadcast can arrive before its reply.
    const frame = once(socket, 'message', { signal: AbortSignal.timeout(settings.requestMs) });
    const [response, [message]] = await Promise.all([
        fetch(`${endpoint}/invoke/Dashboard/dashboard/publish`, {
            method: 'POST',
            headers: { Authorization: `Bearer ${credentials.bearer}`, 'Content-Type': 'application/json' },
            body: JSON.stringify({ args: [] }),
            signal: AbortSignal.timeout(settings.requestMs),
        }),
        frame,
    ]);
    assert.equal(response.status, 200, await response.text());
    assert.deepEqual(JSON.parse(message.data), {
        kind: 'broadcast', channel: 'all', payload: { updated: true },
    });
    console.log('Broadcast-only Dashboard: built, connected with a signed ticket, and received the published update.');
} finally {
    if (socket?.readyState === WebSocket.OPEN) {
        const closed = once(socket, 'close', { signal: AbortSignal.timeout(settings.requestMs) });
        socket.close();
        await closed.catch(() => {});
    }
    await worker?.stop();
    process.chdir(originalDirectory);
    rmSync(temporary, { recursive: true, force: true });
}
