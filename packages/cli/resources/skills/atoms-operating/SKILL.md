---
name: atoms-operating
description: Operate Atoms deployments in this repo — the validate→build→diff→deploy loop, expand/contract deploy ordering, rollback, and reading version-skew errors. Use when deploying, rolling back, or diagnosing a deploy.
---

# Operating Atoms

The CLI is a standalone binary (`atoms`) driven by `atoms.json`. It never boots
your app, so a broken monolith never blocks a deploy.

## The core loop

```sh
atoms validate                 # stages 1–3+5: static boundary/contract/migration checks. Seconds, no network. The PR gate.
atoms build [--fast] [--out D] # deterministic, content-addressed bundle + manifest. --fast skips the vendor stage and refuses (ATOMS-E107) if atoms-composer.json declares packages.
atoms diff [--against M]       # label each manifest change additive / contracting / breaking vs a saved manifest.
atoms deploy --env X [--bundle B]  # build (unless --bundle), stage into the Worker project, then `wrangler deploy` into YOUR Cloudflare account.
atoms dev [--port P]           # build + `wrangler dev` locally. No Cloudflare account needed.
atoms status --env X           # Worker versions (`wrangler versions list`).
atoms rollback --env X [version-id]  # `wrangler rollback` (previous version by default).
atoms secrets:set KEY --env X  # Worker secret, read in the Atom via $this->config().
atoms shared-secret:set --env X  # ATOMS_SHARED_SECRET, read from stdin. The auth root; not readable by Atom code.
atoms shared-secret:unset --env X  # Remove ATOMS_SHARED_SECRET_PREVIOUS, closing a rotation window.
```

Credentials: either `CLOUDFLARE_API_TOKEN` or an existing `wrangler login`
session — with no token set, Atoms injects nothing and Wrangler uses its own.
A token may come from the environment the command was started with or from
`.env.atoms.<env>` beside atoms.json, never from a committed file.
`CLOUDFLARE_ACCOUNT_ID` overrides the environment's `account_id` in atoms.json
on every command, dev and deploy alike; there is no `--account-id` flag and no
check that the two values agree. A single reachable account can still resolve
without either; several reachable accounts produce ATOMS-E075. Whatever the CLI does resolve goes
straight to your own Wrangler; Atoms never proxies or retains it. In CI, supply
them to the deploy action as `cloudflare-api-token` / `cloudflare-account-id`:
a runner has no login session to fall back on.

Every configured environment must have a non-empty `worker_name`; the
top-level `project` is not a fallback. A callback, when needed, is declared in
the top-level `callback_url.<env>` map in `atoms.json`, and resolves the same
way on every command and for every setting: `--callback-url` (on `deploy` and
`dev`), then `ATOMS_CALLBACK_URL` in the environment the command was started
with, then in `.env.atoms.<env>` beside atoms.json, then the file entry. The
nearer source wins silently — nothing is compared and no combination is a
conflict, and an empty or whitespace-only value means "unset" from every source
alike. `deploy` and `dev` print what they resolved and which source supplied
it before doing anything with it. With no source at all there is no callback,
and deploy warns; an absent file entry on its own is not that, since an
`ATOMS_CALLBACK_URL` from either environment layer needs no file entry. The
file entry is read only when no nearer source supplied a value, so nothing in
it can fail a command a nearer source answered: a value containing `${` that is
not a whole-value `${ENV_VAR}` reference is ATOMS-E070 when the file wins, and
is never inspected otherwise. A well-formed reference that resolves to nothing
is ATOMS-E070 on deploy, and on `atoms dev` simply no callback plus a warning —
the variable may be one only CI holds.

`.env.atoms.<env>` is optional, gitignored by `atoms init`, and read only for
the environment named on the command line — never the application's `.env`,
another target's file, or a generic `.env.atoms`. An existing but unparseable
one is ATOMS-E109. This is also why `php artisan atoms:deploy` and
`bin/console atoms:deploy` resolve identically to `vendor/bin/atoms deploy`:
the wrappers hand the child the environment the command was started with, not
the one the framework built after loading the app's `.env`.

Deploy needs the committed Worker directory, `atoms-worker/` beside atoms.json
(or `--worker-dir`; atoms.json does not name it), with `npm ci` already run in
it. `atoms init` prints
the exact version-matched `@atomsphp/runtime-cloudflare init` command; run it
once, `npm ci`, and commit the directory. Atoms runs the pinned Wrangler it
finds there and never downloads one during deploy. Missing: ATOMS-E073/E076.

The directory is released with the CLI. After updating the atoms/* packages,
`atoms deploy` and `atoms dev` refuse a directory from another release
(ATOMS-E108) and print the `atoms-runtime-cloudflare upgrade` command; run
it, `npm ci`, review the diff, commit. It rewrites runtime-owned files and
leaves wrangler.jsonc, which is the user's, as it is.

The Worker's `/debug` routes are off by default. To enable them for an
environment, set `"debug_endpoints": true` on that environment in atoms.json —
not in wrangler.jsonc, which is shared by every environment and would enable
them everywhere. `atoms dev` and `atoms deploy` both forward the setting to
Wrangler as a `--var`. The routes sit behind the Worker's bearer check under
the default `ATOMS_BEARER_AUTH=required`; under `ATOMS_BEARER_AUTH=disabled`
(an authenticating proxy in front of the Worker) the flag is the only gate.

`atoms secrets:set PAYMENTS_API_KEY` stores the Worker secret
`ATOMS_CONFIG_PAYMENTS_API_KEY`, because that is the name the Worker's config
allowlist resolves `$this->config('PAYMENTS_API_KEY')` to. The prefix is read
from the Worker project's wrangler config (`ATOMS_CONFIG_ENV_PREFIX`), so an
overridden one is honoured; a key that could never be read back is refused with
ATOMS-E077 rather than stored.

`ATOMS_SHARED_SECRET` is the one key `secrets:set` will not store (ATOMS-E077):
it is the auth root the bearer, WebSocket ticket and callback keys all derive
from, and prefixing it would put it in the namespace Atom code can read. Use
`atoms shared-secret:set --env X`, which takes the value on stdin only (never
argv), validates it as 32 bytes of base64, and skips the write when the Worker
already has one unless `--force`. `--previous` writes the rotation overlap key.
The Worker needs it before it can serve anything — without it every route
except `GET /healthz` answers `misconfigured`, so a pipeline health check goes
green while every invoke fails. In CI, pass it to the deploy action as
`shared-secret` rather than calling this yourself. The same value must also be
set on the application side, under the same name; nothing in Atoms can reach
that. See docs/shared-secret.md.

Rotating: set the new value with `--force` and the old one with `--previous`
during the overlap window, then run `atoms shared-secret:unset --env X` once
every caller holds the new secret. That command removes the overlap key only
and succeeds when it is already gone. `ATOMS_SHARED_SECRET` itself cannot be
unset — a Worker without it answers `misconfigured` on every route but
`GET /healthz`. In CI the deploy action's `rotate-shared-secret` and
`retire-shared-secret-previous` inputs drive both ends.

## Deploy ordering (expand/contract)

The monolith and the Atom fleet deploy on different schedules — version skew is
permanent, not an edge case. `atoms diff` labels every change:

- **additive** (new Atom type / new method) → **deploy Atoms first**, then the
  monolith that calls them.
- **contracting** (removed type/method) → **deploy the monolith first** (stop
  calling it), then the Atoms.
- **breaking** (changed signature) → treat as contract+expand: add the new
  method alongside the old, migrate callers, then remove the old.

Schema follows the same discipline: migrations are append-only and each must be
backward-compatible one version, because a **code** rollback does **not** roll
back **schema**.

## Reading skew errors

- `ATOMS-E040` (manifest hash mismatch) — the monolith was built against a
  different manifest than is deployed. Run `atoms diff` and fix deploy order.
- `ATOMS-E041` (method not in deployed version) — the monolith is ahead; deploy
  the Atoms first for additive changes.
- `ATOMS-E042` (bundle rejected) — the platform re-validation failed; `atoms
  validate` locally reproduces it exactly.
- `ATOMS-E043` (unsupported core version) — the bundle's `atoms/core` version
  is outside the Worker runtime's supported release line. Update the PHP
  packages and Worker directory to compatible versions, then rebuild.
- `ATOMS-E108` (Worker directory does not match the CLI release) — the
  committed `atoms-worker/` was scaffolded by another release. Run the
  `atoms-runtime-cloudflare upgrade` command in the message, `npm ci`, commit.

## This project's environments

<!-- atoms:generated -->
{{ENVIRONMENTS}}
<!-- /atoms:generated -->
