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
atoms build [--fast] [--out D] # deterministic, content-addressed bundle + manifest. --fast skips php-scoper.
atoms diff [--against M]       # label each manifest change additive / contracting / breaking vs a saved manifest.
atoms deploy --env X [--bundle B]  # build (unless --bundle), stage into the Worker project, then `wrangler deploy` into YOUR Cloudflare account.
atoms dev [--port P]           # build + `wrangler dev` locally. No Cloudflare account needed.
atoms status --env X           # Worker versions (`wrangler versions list`).
atoms rollback --env X [version-id]  # `wrangler rollback` (previous version by default).
atoms secrets:set KEY --env X  # Worker secret, read in the Atom via $this->config().
```

Credentials: `CLOUDFLARE_API_TOKEN` (or `--api-token`) and
`CLOUDFLARE_ACCOUNT_ID` (or `account_id` in atoms.json). They go straight to
your own Wrangler; Atoms never proxies or retains them. In CI, supply them to
the deploy action as `cloudflare-api-token` / `cloudflare-account-id`.

Deploy needs a Worker project directory (`worker_dir` in atoms.json, default
`.atoms/worker`) with `npm ci` already run in it — Atoms runs the Wrangler it
finds there and never downloads one. Missing: ATOMS-E073.

`atoms secrets:set PAYMENTS_API_KEY` stores the Worker secret
`ATOMS_CONFIG_PAYMENTS_API_KEY`, because that is the name the Worker's config
allowlist resolves `$this->config('PAYMENTS_API_KEY')` to. The prefix is read
from the Worker project's wrangler config (`ATOMS_CONFIG_ENV_PREFIX`), so an
overridden one is honoured; a key that could never be read back is refused with
ATOMS-E077 rather than stored.

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

## This project's environments

<!-- atoms:generated -->
{{ENVIRONMENTS}}
<!-- /atoms:generated -->
