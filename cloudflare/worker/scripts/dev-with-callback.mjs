#!/usr/bin/env node

/**
 * dev-with-callback.mjs — `wrangler dev`, with the callback channel wired to
 * a keypair generated for this run only.
 *
 * A committed test key — even a throwaway — gets flagged by every secret
 * scanner that looks at this repository, and this tree already goes out of
 * its way not to ship credentials (README.md §Licensing, THIRD_PARTY_NOTICES.md).
 * So this script generates a fresh Ed25519 keypair every time it runs, passes
 * the seed to the Worker as a `--var` (never a file, never a log — it lives
 * only in this process's argv and the wrangler child's env for the life of
 * one dev session), and writes the PUBLIC half plus the listener port to
 * `test/.callback-key.json`, which is gitignored. `test/conformance.mjs`
 * reads that file to stand up its own in-suite monolith listener and verify
 * signatures against the matching public key.
 *
 * Do not "harden" this into a committed key file. The generated-per-run
 * design costs one script and one CI step; a committed key costs a private
 * key in git forever (design doc §10.2).
 *
 * Usage: node scripts/dev-with-callback.mjs [--port <workerPort>]
 * Env:
 *   ATOMS_CALLBACK_PORT   port the in-suite listener will bind (default 8788)
 *   ATOMS_TURN_DEADLINE_MS  forwarded to the Worker verbatim when set; when
 *                           unset, the Worker's own default applies. Never
 *                           defaulted here — that would be a capacity number
 *                           in a file other than src/config.js.
 */

import { generateKeyPairSync } from 'node:crypto';
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
const turnDeadlineMs = process.env.ATOMS_TURN_DEADLINE_MS;

// M1 in the design doc's probe confirms the PKCS8-prefix trick this derives
// from: subarray(-32) on the DER export is the raw seed / raw public key.
const { privateKey, publicKey } = generateKeyPairSync('ed25519');
const pkcs8 = privateKey.export({ type: 'pkcs8', format: 'der' });
const seed = pkcs8.subarray(pkcs8.length - 32);
const spki = publicKey.export({ type: 'spki', format: 'der' });
const pub = spki.subarray(spki.length - 32);

const seedB64 = seed.toString('base64');
const pubB64 = pub.toString('base64');

const keyFile = join(workerRoot, 'test', '.callback-key.json');
mkdirSync(dirname(keyFile), { recursive: true });
writeFileSync(
    keyFile,
    JSON.stringify(
        {
            $comment: 'Generated per run by scripts/dev-with-callback.mjs. Gitignored. Public key only.',
            publicKey: pubB64,
            port: Number(callbackPort),
        },
        null,
        2
    ) + '\n'
);

const callbackUrl = `http://127.0.0.1:${callbackPort}/atoms/callback`;

const argv = ['dev', '--port', workerPort, '--ip', '127.0.0.1', '--var', `ATOMS_CALLBACK_URL:${callbackUrl}`, '--var', `ATOMS_CALLBACK_SIGNING_KEY:${seedB64}`];
if (typeof turnDeadlineMs === 'string' && turnDeadlineMs !== '') {
    argv.push('--var', `ATOMS_TURN_DEADLINE_MS:${turnDeadlineMs}`);
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
