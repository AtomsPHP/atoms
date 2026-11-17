#!/usr/bin/env node

// Test the public CLI with a generated Worker project and pinned Wrangler without uploading.
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { existsSync, mkdtempSync, mkdirSync, readdirSync, readFileSync, rmSync, symlinkSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { stageRuntimePackage } from '../../cloudflare/worker/scripts/pack-runtime.mjs';

const repo = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const workerSource = join(repo, 'cloudflare/worker');
const temporary = mkdtempSync(join(tmpdir(), 'atoms-deploy-dry-run-'));
const timeout = Number(process.env.ATOMS_SMOKE_STARTUP_MS ?? 120000);
const env = Object.fromEntries(Object.entries(process.env).filter(([key]) =>
    !/^(ATOMS_|CLOUDFLARE_|CF_)/.test(key)));
env.CI = 'true';
env.WRANGLER_SEND_METRICS = 'false';
// Use temporary directories so Wrangler cannot read saved login credentials or configuration.
env.HOME = join(temporary, 'home');
env.XDG_CONFIG_HOME = join(temporary, 'config');

function run(command, args, cwd) {
    const result = spawnSync(command, args, { cwd, env, encoding: 'utf8', timeout });
    assert.equal(result.status, 0, `${command} failed: ${result.error ?? ''}\n${result.stdout}\n${result.stderr}`);
    return result.stdout + result.stderr;
}

try {
    const app = join(temporary, 'app');
    mkdirSync(join(app, 'app/Atoms'), { recursive: true });
    writeFileSync(join(app, 'app/Atoms/Counter.php'), `<?php
        declare(strict_types=1);
        namespace App\\Atoms;
        final class Counter extends \\Atoms\\Atom {
            public function value(): int { return 42; }
        }
    `);
    writeFileSync(join(app, 'atoms.json'), JSON.stringify({
        project: 'dry-run-test',
        paths: { atoms: 'app/Atoms', shared: 'app/Atoms/Shared' },
        environments: {
            production: {
                worker_name: 'dry-run-production',
                callback_url: 'https://app.example.test/atoms/callback',
                custom_domains: ['atoms.example.test'],
                debug_endpoints: true,
            },
        },
    }));
    stageRuntimePackage(join(temporary, 'package'));
    const runtime = join(temporary, 'package/template');
    symlinkSync(join(workerSource, 'node_modules'), join(runtime, 'node_modules'), 'dir');
    run(process.execPath, ['scripts/prepare-runtime.mjs'], runtime);
    const originalConfig = readFileSync(join(runtime, 'wrangler.jsonc'), 'utf8');
    mkdirSync(join(runtime, '.wrangler/deploy'), { recursive: true });
    const redirect = join(runtime, '.wrangler/deploy/config.json');
    writeFileSync(redirect, 'invalid stale redirect');
    const args = [
        join(repo, 'packages/cli/bin/atoms'), 'deploy', '--root', app, '--env', 'production',
        '--worker-dir', runtime, '--dry-run', '--outdir', 'review output',
    ];
    // Use a working directory that differs from both --root and --worker-dir.
    const output = run('php', args, temporary);
    assert.doesNotMatch(output, /Using redirected Wrangler configuration/);
    assert.match(output, /dry-run-production/);
    assert.match(output, /--dry-run: exiting now\./);
    assert.match(output, /Dry run complete\. Nothing was deployed\./);
    assert.doesNotMatch(output, /Deployed dry-run-test|Cloudflare propagates/);
    assert.ok(existsSync(join(temporary, 'review output/index.js')));
    assert.ok(readdirSync(join(temporary, 'review output')).some((file) => file.endsWith('.wasm')));
    assert.ok(existsSync(join(app, '.atoms/build/manifest.json')));
    assert.ok(existsSync(join(runtime, 'src/bundle.generated.js')));
    assert.equal(readFileSync(join(runtime, 'wrangler.jsonc'), 'utf8'), originalConfig);
    assert.equal(readFileSync(redirect, 'utf8'), 'invalid stale redirect');
    assert.ok(!existsSync(join(runtime, '.wrangler/deploy/wrangler.json')));
    assert.ok(!readdirSync(runtime).some((file) => /^\.wrangler\.atoms-.*\.json$/.test(file)));

    // Package a prebuilt bundle without --outdir.
    const bundle = readdirSync(join(app, '.atoms/build')).find((file) => file.endsWith('.tar.gz'));
    assert.ok(bundle);
    const prebuilt = run('php', [
        ...args.slice(0, -2), '--bundle', join(app, '.atoms/build', bundle),
        '--manifest', join(app, '.atoms/build/manifest.json'),
    ], temporary);
    assert.match(prebuilt, /--dry-run: exiting now\./);
    assert.match(prebuilt, /Nothing was deployed/);
    assert.doesNotMatch(prebuilt, /Building bundle/);
    assert.equal(readFileSync(redirect, 'utf8'), 'invalid stale redirect');
    assert.ok(!readdirSync(runtime).some((file) => /^\.wrangler\.atoms-.*\.json$/.test(file)));
    console.log('Deployment dry run: scaffold packaged through the CLI without credentials or upload.');
} finally {
    rmSync(temporary, { recursive: true, force: true });
}
