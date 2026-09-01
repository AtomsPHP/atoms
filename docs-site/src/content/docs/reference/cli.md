---
title: CLI reference
description: The atoms commands for project setup, validation, builds, local development, and Cloudflare operation.
---

Run the project-local executable as `vendor/bin/atoms`. Every command accepts `--root` to select the application root.

| Command | Purpose |
|---|---|
| `init` | Create `atoms.json` and `atoms-composer.json`; print the pinned runtime scaffold command. |
| `make:atom NAME` | Scaffold an Atom; optionally add Methods, a first migration, or WebSocket handlers. |
| `validate` | Discover and statically validate the configured Atom tree. |
| `build` | Produce a deterministic bundle and manifest. |
| `diff` | Compare the current manifest with a saved manifest. |
| `dev` | Build and run the local Worker through its installed Wrangler. |
| `deploy` | Build, stage, and deploy through the installed Wrangler. |
| `status` | List deployed Worker versions for an environment. |
| `rollback [VERSION]` | Move a Worker to an earlier version (default: the previous one). |
| `secrets:set KEY [VALUE]` | Set a secret readable by Atom code through `$this->config()`. |
| `secrets:list` | List Worker secret names for an environment. |
| `shared-secret:set` | Set `ATOMS_SHARED_SECRET` (or, with `--previous`, the rotation overlap) on the Worker, read from stdin. |
| `shared-secret:unset` | Remove the rotation overlap secret from the Worker, closing a rotation window. |
| `token` | Print the bearer derived from `ATOMS_SHARED_SECRET`, for hand-issued requests. |
| `ai:install` | Install or regenerate the Atoms agent guidance. |

## Common flows

```bash
vendor/bin/atoms init --project my-app --path app/Atoms
vendor/bin/atoms make:atom GameRoom --with-methods --with-migration
vendor/bin/atoms validate
vendor/bin/atoms build
vendor/bin/atoms dev --env staging --callback-url http://127.0.0.1:8000/atoms/callback
```

```bash
openssl rand -base64 32 | vendor/bin/atoms shared-secret:set --env production
vendor/bin/atoms deploy --env production
vendor/bin/atoms status --env production
vendor/bin/atoms rollback VERSION_ID --env production --message "known-good code"
```

## Command options

- **`init`** — `--project` (defaults to the directory name), `--path` (defaults to `app/Atoms`). Refuses if `atoms.json` already exists.
- **`make:atom NAME`** — `--with-methods`, `--with-migration`, `--websocket`. `NAME` must be a valid PHP class name.
- **`validate`** — `--json` for machine-readable output.
- **`build`** — `--fast` skips the vendor stage (refuses with `ATOMS-E107` if `atoms-composer.json` declares packages); `--out` (defaults to `.atoms/build`).
- **`diff`** — `--against` a saved `manifest.json` to compare with the current one.
- **`dev`** — `--env` (defaults to `staging`), `--port` (defaults to `8787`), `--callback-url` (else `atoms.json`'s `callback_url`), `--worker-dir` (else `atoms.json`), `--no-build` to reuse the bundle already staged in the Worker project.
- **`deploy`** — `--env` (required), `--bundle` to deploy a prebuilt bundle instead of building, `--manifest` (defaults to `manifest.json` beside `--bundle`), `--worker-dir`.
- **`status`**, **`secrets:list`**, **`shared-secret:unset`** — `--env` (required), `--worker-dir`.
- **`rollback [VERSION]`** — `--env` (required), `--message`/`-m`, `--worker-dir`. `VERSION` defaults to the previous version.
- **`secrets:set KEY [VALUE]`** — `--env` (required), `--worker-dir`. Reads the value from stdin when the `VALUE` argument is omitted.
- **`shared-secret:set`** — `--env` (required), `--worker-dir`, `--previous` to target `ATOMS_SHARED_SECRET_PREVIOUS` instead of `ATOMS_SHARED_SECRET`, `--force` to overwrite an existing value. Always reads the secret from stdin, never an argument. Idempotent unless `--force`.
- **`token`** — `--env` (defaults to `staging`, used only to resolve a fallback `.dev.vars`), `--worker-dir`.

## The shared secret

`ATOMS_SHARED_SECRET` is the one operator-facing secret: 32 random bytes, base64-encoded, configured identically on the Worker and the application. Every credential on that boundary — the `Authorization` bearer, WebSocket ticket verification, and callback signing — is HKDF-derived from it.

```bash
openssl rand -base64 32 | vendor/bin/atoms shared-secret:set --env production
```

`shared-secret:set` is the only CLI path to this key: `secrets:set` refuses it (`ATOMS-E077`), because that command writes the `ATOMS_CONFIG_`-prefixed namespace Atom code can read, and the boundary root must never live there. The value is validated as 32 bytes of base64 before it is sent, and the command is idempotent — an existing value is left alone unless `--force` is passed, so running it on every deploy does not mint a new Worker version.

Rotate without downtime by setting the new value with `--force`, setting the old value as the overlap with `--previous`, then running `shared-secret:unset` once every instance on both sides holds the new secret. `token` prints the bearer this secret derives, so you can curl a Worker without ever pasting the secret itself into a header:

```bash
curl -H "Authorization: Bearer $(vendor/bin/atoms token --env production)" \
  https://your-worker.example.workers.dev/healthz
```

A deployed Worker's `ATOMS_BEARER_AUTH` setting (`required`, the default, or `disabled` for a deployment sitting behind an authenticating proxy) governs whether that bearer is checked on invocation and WebSocket routes; it has no effect on ticket or callback signing, which run whenever a usable secret is configured. See [Callbacks](/guides/callbacks/#configure-the-channel) and [Deploy](/guides/deploy/#configure-callbacks-and-application-secrets) for where each piece is configured, or [`docs/shared-secret.md`](https://github.com/AtomsPHP/atoms/blob/main/docs/shared-secret.md) in the monorepo for the full contract.

## Wrangler resolution

Deployment commands use, in order:

1. absolute `ATOMS_WRANGLER_BIN`;
2. `node_modules/.bin/wrangler` under the selected Worker directory;
3. a global `wrangler` on `PATH`.

The CLI never invokes `npx` and never installs tooling. Run `npm ci` in the scaffolded Worker directory first.

## Credentials

`CLOUDFLARE_API_TOKEN` and `CLOUDFLARE_ACCOUNT_ID` pass directly to Wrangler’s environment. The CLI does not accept a token argument, write it to disk, or retain it.

`secrets:set NAME` maps application-facing names through the Worker’s configured allowlist prefix, normally `ATOMS_CONFIG_`. It cannot set `ATOMS_SHARED_SECRET` or its rotation overlap; use `shared-secret:set`/`shared-secret:unset` for those.

There is no `atoms usage` or point-in-time recovery command in 0.4.
