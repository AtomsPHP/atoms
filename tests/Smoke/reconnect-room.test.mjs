import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { EventEmitter, once } from 'node:events';
import { cpSync, mkdtempSync, readFileSync, rmSync, symlinkSync, writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { stageRuntimePackage } from '../../cloudflare/worker/scripts/pack-runtime.mjs';
import { reconnectSocket } from '../../packages/client/resources/reconnect-socket.mjs';

const repo = fileURLToPath(new URL('../../', import.meta.url));
const source = join(repo, 'cloudflare/worker');
const { unstable_dev } = createRequire(join(source, 'package.json'))('wrangler');
const temporary = mkdtempSync(join(tmpdir(), 'atoms-reconnect-'));
const app = join(temporary, 'app');
const runtime = join(temporary, 'package/template');
const secret = randomBytes(32).toString('base64');
const timeout = Number(process.env.ATOMS_SMOKE_REQUEST_MS ?? 30000);
const clients = [];
const originalDirectory = process.cwd();
process.chdir(temporary);
let worker;

for (const key of Object.keys(process.env)) {
    if (/^(ATOMS_|CLOUDFLARE_|CF_)/.test(key)) delete process.env[key];
}
process.env.CI = 'true';
process.env.WRANGLER_SEND_METRICS = 'false';

async function startWorker() {
    execFileSync('php', [join(repo, 'packages/cli/bin/atoms'), 'build', '--fast'], { cwd: app });
    const build = join(app, '.atoms/build');
    const manifest = join(build, 'manifest.json');
    const { content_hash: hash } = JSON.parse(readFileSync(manifest, 'utf8'));
    execFileSync(process.execPath, ['scripts/bundle-from-cli.mjs', join(build, `bundle-${hash}.tar.gz`), manifest], { cwd: runtime });
    return unstable_dev(join(runtime, 'src/index.js'), {
        config: join(runtime, 'wrangler.jsonc'), ip: '127.0.0.1', port: 0, inspectorPort: 0,
        local: true, persist: true, persistTo: join(temporary, 'state'), logLevel: 'error',
        experimental: { disableExperimentalWarning: true, disableDevRegistry: true, watch: false },
    });
}

function credentials(player) {
    return JSON.parse(execFileSync('php', ['-r', String.raw`
        require $argv[1];
        $config = new \Atoms\Client\AtomsConfig($argv[2], getenv('ATOMS_SHARED_SECRET'));
        $issuer = new \Atoms\Client\Tickets\TicketIssuer($config);
        echo json_encode([
            'ticket' => (string) $issuer->issue('Turns', 'table', ['client_id' => $argv[3]]),
            'bearer' => $config->bearerToken(),
        ], JSON_THROW_ON_ERROR);
    `, join(repo, 'vendor/autoload.php'), `http://127.0.0.1:${worker.port}`, player], {
        env: { ...process.env, ATOMS_SHARED_SECRET: secret }, encoding: 'utf8',
    }));
}

function connectPlayer(player) {
    const events = new EventEmitter();
    let current;
    const stop = reconnectSocket(() => {
        const url = new URL(`ws://127.0.0.1:${worker.port}/ws/Turns/table`);
        url.searchParams.set('ticket', credentials(player).ticket);
        url.searchParams.set('channels', 'game');
        return url;
    }, (socket) => {
        current = socket;
        socket.addEventListener('message', ({ data }) => {
            const frame = JSON.parse(data);
            events.emit(frame.kind, frame);
        });
    });
    clients.push(stop);
    return {
        next: async (kind) => (await once(events, kind, { signal: AbortSignal.timeout(timeout) }))[0],
        move: (name, revision) => current.send(JSON.stringify({ name, revision })),
    };
}

try {
    cpSync(join(repo, 'examples/reconnecting-room'), app, { recursive: true });
    stageRuntimePackage(join(temporary, 'package'));
    symlinkSync(join(source, 'node_modules'), join(runtime, 'node_modules'), 'dir');
    execFileSync(process.execPath, ['scripts/prepare-runtime.mjs'], { cwd: runtime });
    writeFileSync(join(runtime, '.dev.vars'), `ATOMS_SHARED_SECRET=${secret}\n`);
    worker = await startWorker();
    const response = await fetch(`http://127.0.0.1:${worker.port}/invoke/Turns/table/start`, {
        method: 'POST', body: JSON.stringify({ args: ['Alice', 'Bob'] }),
        headers: { Authorization: `Bearer ${credentials('Alice').bearer}`, 'Content-Type': 'application/json' },
        signal: AbortSignal.timeout(timeout),
    });
    assert.equal(response.status, 200, await response.text());

    const alice = connectPlayer('Alice');
    const bob = connectPlayer('Bob');
    const welcomes = () => Promise.all([alice.next('welcome'), bob.next('welcome')]);
    const [first, second] = await welcomes();
    assert.equal(first.seat, 1);
    assert.equal(second.seat, 2);
    const accepted = bob.next('broadcast');
    alice.move('e4', 0);
    assert.deepEqual((await accepted).payload, { revision: 1, last_move: 'e4' });

    const resumed = welcomes();
    await worker.stop();
    // Only the second bundle identifies its release in the welcome frame.
    const atom = join(app, 'app/Atoms/Turns.php');
    writeFileSync(atom, readFileSync(atom, 'utf8').replace("'kind' => 'welcome'", "'release' => 2, 'kind' => 'welcome'"));
    worker = await startWorker();
    for (const [i, frame] of (await resumed).entries()) {
        assert.equal(frame.release, 2);
        assert.equal(frame.seat, i + 1);
        assert.deepEqual(frame.game, { revision: 1, last_move: 'e4' });
    }

    const nextMove = alice.next('broadcast');
    bob.move('e5', 1);
    assert.deepEqual((await nextMove).payload, { revision: 2, last_move: 'e5' });
} finally {
    for (const stop of clients) stop();
    await worker?.stop();
    process.chdir(originalDirectory);
    rmSync(temporary, { recursive: true, force: true });
}
