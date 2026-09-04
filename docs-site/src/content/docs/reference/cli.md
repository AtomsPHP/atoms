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

## Local development

[Initialize the project](/getting-started/install/#initialize-the-project)
and install the Worker dependencies first, then run:

```bash
vendor/bin/atoms make:atom GameRoom --with-methods --with-migration
vendor/bin/atoms dev --env staging --callback-url http://127.0.0.1:8000/atoms/callback
```

`dev` builds the bundle before starting the Worker. Use `atoms validate` for
a check without a bundle, or `atoms build` to create one without starting a Worker.

## Deployment

After [configuring deployment](/guides/deploy/):

```bash
vendor/bin/atoms deploy --env production
vendor/bin/atoms status --env production
```

To restore a selected Worker version, follow [Rollback](/guides/rollback/).

## Command options

- **`init`** — `--project` (defaults to the directory name), `--path` (defaults to `app/Atoms`). Refuses if `atoms.json` already exists.
- **`make:atom NAME`** — `--with-methods`, `--with-migration`, `--websocket`. `NAME` must be a valid PHP class name.
- **`validate`** — `--json` for machine-readable output.
- **`build`** — `--fast` skips the vendor stage (refuses with `ATOMS-E107` if `atoms-composer.json` declares packages); `--out` (defaults to `.atoms/build`).
- **`diff`** — `--against` a saved `manifest.json` to compare with the current one.
- **`dev`** — `--env` (defaults to `staging`), `--port` (defaults to `8787`), `--callback-url` (else `atoms.json`'s `callback_url`), `--worker-dir` (defaults to `atoms-worker/` beside `atoms.json`), `--no-build` to reuse the bundle already staged in the Worker project.
- **`deploy`** — `--env` (required), `--bundle` to deploy a prebuilt bundle instead of building, `--manifest` (defaults to `manifest.json` beside `--bundle`), `--worker-dir`.
- **`status`**, **`secrets:list`**, **`shared-secret:unset`** — `--env` (required), `--worker-dir`.
- **`rollback [VERSION]`** — `--env` (required), `--message`/`-m`, `--worker-dir`. `VERSION` defaults to the previous version.
- **`secrets:set KEY [VALUE]`** — `--env` (required), `--worker-dir`. Reads the value from stdin when the `VALUE` argument is omitted.
- **`shared-secret:set`** — `--env` (required), `--worker-dir`, `--previous` to target `ATOMS_SHARED_SECRET_PREVIOUS` instead of `ATOMS_SHARED_SECRET`, `--force` to overwrite an existing value. Always reads the secret from stdin, never an argument. Leaves an existing secret unchanged unless you pass `--force`.
- **`token`** — `--env` (defaults to `staging`, used only to resolve a fallback `.dev.vars`), `--worker-dir`.

## The shared secret

`ATOMS_SHARED_SECRET` is a base64-encoded value that decodes to 32 random
bytes. Generate it once, save it in your secret manager, and configure the
same value on your application and Worker. With that value loaded into your
shell environment:

```bash
printf '%s' "$ATOMS_SHARED_SECRET" | \
  vendor/bin/atoms shared-secret:set --env production
```

The Worker must already exist. The command leaves an existing secret unchanged
unless you pass `--force`; it does not compare the stored and supplied values.
See [Deploy](/guides/deploy/#configure-callbacks-and-application-secrets) for
first-time setup and [secret rotation](/guides/deploy/#rotate-the-shared-secret).

The shared secret is not a bearer token. Use `atoms token` to derive the bearer
for a manual request. This example calls the `join` method from the
[overview](/), which adds a player to the specified room:

```bash
curl -H "Authorization: Bearer $(vendor/bin/atoms token --env production)" \
  -H 'Content-Type: application/json' \
  --data '{"args":["ada"]}' \
  https://your-worker.example.workers.dev/invoke/GameRoom/room-42/join
```

`token` reads the local secret; it does not fetch the deployed Worker's value.
Set `ATOMS_SHARED_SECRET` to the production value before using this example.
The public `/healthz` route does not check bearer authentication.

`ATOMS_BEARER_AUTH` defaults to `required`. Use `disabled` only behind an
authenticating proxy. The shared secret is required in either case, and
WebSocket tickets and callbacks remain signed.

## Worker directory

Commands use `atoms-worker/` beside `atoms.json`, or the directory supplied
with `--worker-dir`. Commit this directory, including `wrangler.jsonc`,
`package-lock.json`, and `atoms-runtime.json`. `atoms.json` does not configure
its location.

`dev` and `deploy` check that the runtime stamp matches the CLI's exact
release before building. See [Upgrade the runtime](/guides/deploy/#upgrade-the-runtime)
for the upgrade command and which files it replaces.

## Wrangler resolution

Deployment commands use, in order:

1. absolute `ATOMS_WRANGLER_BIN`;
2. `node_modules/.bin/wrangler` under the selected Worker directory;
3. a global `wrangler` on `PATH`.

The CLI never invokes `npx` and never installs tooling. Run `npm ci` in the scaffolded Worker directory first.

## Credentials

`CLOUDFLARE_API_TOKEN` and `CLOUDFLARE_ACCOUNT_ID` pass directly to Wrangler’s environment. The CLI does not accept a token argument, write it to disk, or retain it.

`secrets:set NAME` maps application-facing names through the Worker’s configured allowlist prefix, normally `ATOMS_CONFIG_`. It cannot set `ATOMS_SHARED_SECRET` or its rotation overlap; use `shared-secret:set`/`shared-secret:unset` for those.

For data recovery limitations, see [Rollback](/guides/rollback/#data-recovery).
