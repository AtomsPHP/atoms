---
name: atoms-operating
description: Build, deploy, and roll back Atoms with the CLI. Use when configuring a Worker, rotating secrets, or diagnosing a deployment.
---

# Operating Atoms

## CLI commands

The standalone `atoms` CLI reads `atoms.json` without booting the application.
Replace `staging` with the configured environment you want to use. Each line is a
separate command.

```sh
atoms validate                         # Check boundaries, contracts, and migrations offline.
atoms build --out .atoms/build         # Write the bundle and manifest to this directory.
atoms diff --against previous.json     # Compare with a saved manifest.
atoms dev --env staging --port 8787    # Start the local Worker.
atoms deploy --env staging             # Build and deploy to Cloudflare.
atoms status --env staging             # List Worker versions.
atoms rollback --env staging           # Roll back to the previous Worker version.
atoms secrets:set PAYMENTS_API_KEY --env staging  # Set an application secret on the Worker.
atoms shared-secret:set --env staging             # Read the Worker authentication secret from stdin.
atoms shared-secret:unset --env staging           # Remove the previous key after rotation.
```

Use `--fast` on a build to skip the vendor stage when `atoms-composer.json`
declares no packages. Use `--bundle` on deploy to supply a prebuilt bundle.
To roll back to a specific Worker version, pass the version ID from `atoms status`
to `atoms rollback`.

## Worker setup

Run the version-matched Worker init command printed by `atoms init`. Run
`npm ci` in `atoms-worker/` and commit the Worker project. Deploy uses its
pinned Wrangler. Use `--worker-dir` if the directory is elsewhere. Check this
directory for `ATOMS-E076` and its Wrangler installation for `ATOMS-E073`.

If an Atoms package update produces `ATOMS-E108`, the Worker directory
belongs to a different release. Run the upgrade command from the error
message, run `npm ci`, review the diff, and commit it. The upgrade
preserves your `wrangler.jsonc`.

## Credentials and environments

Wrangler uses `CLOUDFLARE_API_TOKEN` or an existing `wrangler login` session.
Set the token in the shell or in the gitignored `.env.atoms.<env>` beside
`atoms.json`. In CI, pass `cloudflare-api-token` and `cloudflare-account-id`
to the deploy action. `CLOUDFLARE_ACCOUNT_ID` overrides the environment's
`account_id`. If Wrangler can access several accounts, specify one to avoid
`ATOMS-E075`. Local development requires no Cloudflare account.

Commands that accept `--env` require an environment name. Each environment
needs its own `worker_name`. The `atoms token` command derives a bearer from
`ATOMS_SHARED_SECRET` and takes no environment option.

For `dev` and `deploy`, the CLI writes a temporary Wrangler config beside
`wrangler.jsonc` and selects it with `--config`. This keeps relative paths,
local storage, and `.dev.vars` in the Worker directory. Wrangler ignores any
temporary copy left after an interruption.

- Set hostnames in the environment's `custom_domains` in `atoms.json`.
  A `routes` key there raises `ATOMS-E070`. Deploy ignores top-level routing
  and `env` blocks in `wrangler.jsonc`. A deploy can move a hostname from
  another environment or replace its DNS record without prompting. `ATOMS-E110`
  indicates that the script uploaded but Wrangler failed to attach a zone route.
- Set `debug_endpoints` to `true` on the environment that needs `/debug`.
  A top-level Wrangler variable applies to every environment. Debug routes
  require a bearer by default. With `ATOMS_BEARER_AUTH=disabled`, the
  authenticating proxy must control access.

## Callback URL

`dev` and `deploy` print the callback URL and its source. The CLI uses the
first nonblank value in this order.

1. The `--callback-url` option.
2. `ATOMS_CALLBACK_URL` in the process environment.
3. `ATOMS_CALLBACK_URL` in `.env.atoms.<env>`.
4. The environment's `callback_url` in `atoms.json`.

A file entry can be a literal URL or a single `${VAR_NAME}` placeholder.
Mixing a placeholder with other text raises `ATOMS-E070` when the CLI uses that
entry. An unset placeholder stops a deploy. Local development warns and runs
without callbacks. If no source supplies a URL, deploy warns and proceeds
without callbacks.

`atoms init` gitignores `.env.atoms.<env>`. The CLI reads only the selected
environment's file. A malformed file raises `ATOMS-E109`. Artisan and Symfony
deploy wrappers pass the original process environment to the CLI, so values the
framework loaded later from the application dotenv file do not affect deployment.

## Worker secrets

`atoms secrets:set PAYMENTS_API_KEY --env staging` stores the value for
`$this->config('PAYMENTS_API_KEY')`. The Worker variable defaults to
`ATOMS_CONFIG_PAYMENTS_API_KEY`. The CLI uses the prefix from
`ATOMS_CONFIG_ENV_PREFIX` if configured. It refuses unreadable keys and
both shared-secret names with `ATOMS-E077`.

Configure the same `ATOMS_SHARED_SECRET` on the Worker and the application.
Use 32 random bytes encoded as base64. The application and Worker derive
bearer, WebSocket ticket, and callback keys from this value. Without it, the
Worker returns `misconfigured` on every route except `GET /healthz`.

Pass the value on stdin to `atoms shared-secret:set --env staging`, or use the
deploy action's `shared-secret` input. The command preserves an existing value
unless you pass `--force`.

### Rotate the shared secret

1. Keep the old `ATOMS_SHARED_SECRET` on the application. Set
   `ATOMS_SHARED_SECRET_PREVIOUS` to the new value and reload every instance.
   All instances can now verify callbacks signed with either key.
2. On the Worker, pass the old value on stdin to
   `atoms shared-secret:set --env staging --previous --force`. Wait for that
   change to propagate. Pass the new value to `atoms shared-secret:set --env staging --force`
   and wait for it to propagate before switching the application.
3. Set the application's current key to the new value and its previous key to
   the old value. Reload every instance.
4. After both deployments have updated and old tickets have expired, run
   `atoms shared-secret:unset --env staging`. Remove the previous key from the
   application and reload it.

Check calls, callbacks, and browser connections between steps. For step 2 in
CI, use two deploy action runs with `rotate-shared-secret: true`. Supply only
the old `shared-secret-previous` on the first run and only the new `shared-secret`
on the second. At step 4, use `retire-shared-secret-previous: true`.

## Deploy order

Use `atoms diff` to compare the manifest with the deployed version's saved manifest.

- For a new Atom type or method, deploy the Worker before its callers.
- To remove a type or method, deploy the application that stops calling it first.
- To change a signature, add the new method alongside the old one, migrate
  callers, then remove the old method.

A code rollback leaves the SQLite schema in place. Append migrations and keep
the schema compatible with the previous code version.

## Deployment errors

The client sends `X-Atoms-Manifest-Hash`, but the Worker does not compare it, so
`ATOMS-E040` is reserved and never emitted. Use `atoms diff` before deploying to
check deploy order.

| Code | What to check |
| --- | --- |
| `ATOMS-E041` | The deployed Worker lacks the requested method. Deploy the Worker before its caller. |
| `ATOMS-E042` | The platform rejected the bundle. Run `atoms validate` locally. |
| `ATOMS-E043` | The bundle's `atoms/core` version is outside the Worker's supported range. Update the packages and Worker directory, then rebuild. |

## This project's environments

<!-- atoms:generated -->
{{ENVIRONMENTS}}
<!-- /atoms:generated -->
