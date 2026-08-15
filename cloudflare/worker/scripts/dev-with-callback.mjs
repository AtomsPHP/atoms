#!/usr/bin/env node

/**
 * dev-with-callback.mjs — `wrangler dev`, with a shared secret generated for
 * this run only and the callback channel pointed at the suite's own listener.
 *
 * The Worker requires `ATOMS_SHARED_SECRET`: 32 random bytes, base64, the root
 * every key on the boundary is derived from (bearer, ticket signatures,
 * callback signatures). This script generates a fresh one every time it runs,
 * passes it to the Worker as a `--var`, and writes it plus the listener port to
 * `test/.dev-secret.json`, which is gitignored. `test/conformance.mjs` reads
 * that file to derive the same three keys — so it can send the bearer, mint and
 * forge tickets, and verify the HMAC on every callback it receives.
 *
 * That file holds the per-run ROOT, not a public half: treat it as a secret.
 * It is regenerated per run, gitignored, and never committed. A committed or
 * fixed dev secret would be a known master key for every deployment that ever
 * copied it, so the secret is always generated, never stored in the repository.
 *
 * Where the secret actually lives, stated plainly rather than politely: it is
 * an ARGV element of the wrangler child process (`--var
 * ATOMS_SHARED_SECRET:<secret>`) and a 0600 file under `test/`, so it is
 * visible to anything on the machine that can read `/proc/<pid>/cmdline`, run
 * `ps`, or read the file as that user. Never a log, never committed — but not
 * private from a local user either. That is accepted for the scope this script
 * has: a throwaway secret, generated per run, for local development and CI. A
 * deployed Worker puts it in `wrangler secret put ATOMS_SHARED_SECRET`.
 *
 * Usage: node scripts/dev-with-callback.mjs [--port <workerPort>]
 * Env:
 *   ATOMS_CALLBACK_PORT   port the in-suite listener will bind (default 8788)
 *   ATOMS_BEARER_AUTH     forwarded verbatim when set: `required` (the Worker's
 *                         own default) or `disabled` for the proxy-fronted
 *                         posture. Never defaulted here.
 *   ATOMS_SHARED_SECRET_PREVIOUS  forwarded verbatim when set: the rotation
 *                         overlap, for the checks that assert bearer(previous)
 *                         is accepted and a previous-secret ticket is not.
 *   ATOMS_TURN_DEADLINE_MS  forwarded to the Worker verbatim when set; when
 *                           unset, the Worker's own default applies. Never
 *                           defaulted here — that would be a capacity number
 *                           in a file other than src/config.js.
 *   ATOMS_SQL_MAX_ROWS, ATOMS_SQL_MAX_RESULT_BYTES  forwarded to the Worker
 *                           verbatim when set (conformance check 29's result-
 *                           set size guard); when unset, the Worker's own
 *                           defaults apply. Never defaulted here — same rule
 *                           as ATOMS_TURN_DEADLINE_MS above.
 *   ATOMS_WS_TICKET_TTL_MS, ATOMS_WS_TICKET_SKEW_MS  forwarded verbatim when
 *                           set (the signed-expiry leg needs a short TTL and a
 *                           known skew); never defaulted here.
 */

import { randomBytes } from 'node:crypto';
import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const workerRoot = join(here, '..');

const DEFAULT_WORKER_PORT = '8787'; // matches wrangler.jsonc's dev.port / CI's WORKER_PORT
const DEFAULT_CALLBACK_PORT = '8788';

function parsePort(argv) {
    const i = argv.indexOf('--port');
    if (i === -1 || i + 1 >= argv.length) return DEFAULT_WORKER_PORT;
    return argv[i + 1];
}

const workerPort = parsePort(process.argv.slice(2));
const callbackPort = process.env.ATOMS_CALLBACK_PORT || DEFAULT_CALLBACK_PORT;

// Pass-throughs: forwarded verbatim when set, never defaulted here.
const passThrough = [
    'ATOMS_BEARER_AUTH',
    'ATOMS_SHARED_SECRET_PREVIOUS',
    'ATOMS_TURN_DEADLINE_MS',
    'ATOMS_SQL_MAX_ROWS',
    'ATOMS_SQL_MAX_RESULT_BYTES',
    'ATOMS_WS_TICKET_TTL_MS',
    'ATOMS_WS_TICKET_SKEW_MS',
];

// 32 random bytes, base64 — exactly what the Worker requires and what the app
// side would hold. Fresh every run.
const sharedSecret = randomBytes(32).toString('base64');

const secretFile = join(workerRoot, 'test', '.dev-secret.json');
mkdirSync(dirname(secretFile), { recursive: true });
writeFileSync(
    secretFile,
    JSON.stringify(
        {
            $comment:
                'Generated per run by scripts/dev-with-callback.mjs. Gitignored, never committed. ' +
                'This is the per-run ROOT secret: bearer, ticket and callback keys all derive from it.',
            sharedSecret,
            port: Number(callbackPort),
        },
        null,
        2
    ) + '\n',
    { mode: 0o600 }
);

const callbackUrl = `http://127.0.0.1:${callbackPort}/atoms/callback`;

const argv = [
    'dev',
    '--port',
    workerPort,
    '--ip',
    '127.0.0.1',
    '--var',
    `ATOMS_CALLBACK_URL:${callbackUrl}`,
    '--var',
    `ATOMS_SHARED_SECRET:${sharedSecret}`,
];
for (const name of passThrough) {
    const value = process.env[name];
    if (typeof value === 'string' && value !== '') {
        argv.push('--var', `${name}:${value}`);
    }
}

const wranglerBin = join(workerRoot, 'node_modules', '.bin', 'wrangler');

console.error(`Starting wrangler dev on port ${workerPort} (callback URL ${callbackUrl})`);

const child = spawn(wranglerBin, argv, {
    cwd: workerRoot,
    stdio: 'inherit',
    env: process.env,
});

child.on('exit', (code, signal) => {
    process.exit(code ?? (signal ? 1 : 0));
});
