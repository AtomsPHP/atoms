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
| `rollback` | Move a Worker to an earlier version. |
| `secrets:set` | Set a secret readable by Atom code through `$this->config()`. |
| `secrets:list` | List Worker secret names for an environment. |
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
vendor/bin/atoms deploy --env production
vendor/bin/atoms status --env production
vendor/bin/atoms rollback VERSION_ID --env production --message "known-good code"
```

## Wrangler resolution

Deployment commands use, in order:

1. absolute `ATOMS_WRANGLER_BIN`;
2. `node_modules/.bin/wrangler` under the selected Worker directory;
3. a global `wrangler` on `PATH`.

The CLI never invokes `npx` and never installs tooling. Run `npm ci` in the scaffolded Worker directory first.

## Credentials

`CLOUDFLARE_API_TOKEN` and `CLOUDFLARE_ACCOUNT_ID` pass directly to Wrangler’s environment. The CLI does not accept a token argument, write it to disk, or retain it.

`secrets:set NAME` maps application-facing names through the Worker’s configured allowlist prefix. It cannot set operational runtime secrets such as `ATOMS_CALLBACK_SIGNING_KEY`; use Wrangler for those.

There is no `atoms usage` or point-in-time recovery command in 0.4.
