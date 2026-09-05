---
title: Deploy
description: Build an Atom bundle and deploy its Worker through Wrangler.
---

Atoms are deployed directly to your Cloudflare account, by the
`vendor/bin/atoms` CLI that ships with `atoms/cli`. It drives the Wrangler
installed in your Worker project; it never fetches a toolchain of its own, and
never stores a Cloudflare credential.

## Prepare the Worker

Follow [Initialize the project](/getting-started/install/#initialize-the-project)
to set up `atoms-worker/`. On subsequent checkouts, install its dependencies
with `npm ci`.

## Configure an environment

An environment is a named entry under `environments` in `atoms.json`. You
choose the names; `atoms init` scaffolds `production` and `staging`:

```jsonc
{
    "project": "my-app",
    "environments": {
        "production": {
            // Base URL the deployed Worker serves on.
            "endpoint": "https://my-app.<your-subdomain>.workers.dev",
            // The Cloudflare Worker to deploy to.
            "worker_name": "my-app",
            // Only needed if your credentials can reach more than one account.
            "account_id": "",
            "debug_endpoints": false
        },
        "staging": {
            "endpoint": "https://my-app-staging.<your-subdomain>.workers.dev",
            "worker_name": "my-app-staging",
            "account_id": "",
            "debug_endpoints": true
        }
    }
}
```

Every command below — `deploy`, `status`, `rollback`, `secrets:set`,
`shared-secret:set` — takes `--env <name>`, which looks up that key and uses
its entry for the run. Against the file above,
`vendor/bin/atoms deploy --env production` deploys to
the Worker `my-app`, in the account named by `account_id` (or
`CLOUDFLARE_ACCOUNT_ID`, or whichever account your login can reach), with the
`/debug` routes off; `--env staging` deploys the same code to the separate
Worker `my-app-staging` with them on. The entry's `endpoint` is not used to
deploy — it is what `deploy` and `status` report back, and what you set as
`ATOMS_ENDPOINT` in the app that calls this environment's Atoms. `--env` also
selects the matching key under the top-level `callback_url`, which supplies the
Worker's `ATOMS_CALLBACK_URL` unless `--callback-url` or the process
environment overrides it.

:::caution
Give each environment its own `worker_name`. It defaults to the top-level
`project`, so two environments that both leave it out point at one Worker and
deploy over each other.
:::

These are not Wrangler environments. `atoms deploy` always selects the Worker
with `wrangler deploy --name` and never passes Wrangler's own `-e`/`--env`, so
`env.<name>` sections in `wrangler.jsonc` do not apply to what it deploys. Put
routes, custom domains, and runtime settings at the top level of
`atoms-worker/wrangler.jsonc` — that one file serves every environment, which
is why the setting that must differ between them, `debug_endpoints`, lives in
`atoms.json` instead.

On your own machine, authenticate with the installed Wrangler:

```bash
cd atoms-worker
./node_modules/.bin/wrangler login
cd ..
```

When `CLOUDFLARE_API_TOKEN` is unset, Wrangler uses its saved login session.

For headless or scripted deploys, set an API token in the environment instead:

```bash
export CLOUDFLARE_API_TOKEN='…'
```

If you are using a token, it needs permission to edit Workers Scripts in the target account. Do not commit it to your repository.

## Build and deploy

```bash
vendor/bin/atoms deploy --env production
```

`deploy` validates and bundles your Atom code and dependencies, then deploys the Worker through Wrangler. Use `atoms build` to produce a bundle for inspection or later deployment.

You can validate without a build with `atoms validate`. Pass the `--json` flag for JSON output.

## Dependencies that ship with the Atom

Packages listed in `atoms-composer.json` are resolved with `composer install --no-scripts --no-plugins` in an isolated directory, written back to `atoms-composer.lock` for reproducibility, and cached under `.atoms/vendor-cache`.

## Configure callbacks and application secrets

Generate one shared secret with `openssl rand -base64 32` and save it in your
secret manager. Configure that same value as `ATOMS_SHARED_SECRET` in your
application and CI environment, then supply it to the Worker:

```bash
printf '%s' "$ATOMS_SHARED_SECRET" | \
  vendor/bin/atoms shared-secret:set --env production
```

Run this after the first deployment, because the Worker must exist before a
secret can be set. Until then, routes other than `/healthz` return a
configuration error. Application requests authenticate with a bearer token
derived from the shared secret.

The command leaves an existing secret unchanged unless you pass `--force`.
See [Rotate the shared secret](#rotate-the-shared-secret) when changing it.

The Worker environment variable `ATOMS_BEARER_AUTH` controls whether the
Worker checks the `Authorization` bearer your application sends automatically.
Leave it at the default, `required`; set it to `disabled` only when an
authenticating proxy such as Cloudflare Access already sits in front of the
Worker. `ATOMS_SHARED_SECRET` stays mandatory in either posture, and browser
connections are unaffected: they authenticate with a short-lived
[ticket](/guides/websockets-timers/) either way.

If your Atoms call `app()` or `dispatch()`, set `ATOMS_CALLBACK_URL` to your
application's callback endpoint. See [Callback URL](/guides/callbacks/#callback-url)
for local and deployed configuration.

Use the Atoms CLI for values your Atom reads through `$this->config()`:

```bash
printf '%s' "$PAYMENTS_API_KEY" | \
  vendor/bin/atoms secrets:set PAYMENTS_API_KEY --env production
```

This stores `ATOMS_CONFIG_PAYMENTS_API_KEY`, readable through
`$this->config('PAYMENTS_API_KEY')` with the default configuration prefix.

To call a protected route manually, see [The shared secret](/reference/cli/#the-shared-secret)
for a bearer-token example.

## Rotate the shared secret

Senders use `ATOMS_SHARED_SECRET`; verifiers accept that value and
`ATOMS_SHARED_SECRET_PREVIOUS`. Prepare the overlap before replacing the
current value. Starting with the same old secret on the application and Worker:

1. Configure every application instance with the old value as current and
   the new value as `ATOMS_SHARED_SECRET_PREVIOUS`. Reload the instances so
   all can verify callbacks signed with either value.
2. Set the Worker's `ATOMS_SHARED_SECRET_PREVIOUS` to the old value with
   `shared-secret:set --previous --force`. Let that change propagate before
   setting its current secret to the new value with `shared-secret:set --force`.
3. Configure the application with the new value as current and the old value
   as previous. Reload all application instances. Both sides now send with
   the new value and accept both.
4. After both deployments have updated and old tickets have expired, run
   `shared-secret:unset` on the Worker and remove the previous value from the
   application. Reload the application again.

Pass `--env production` to these commands. `shared-secret:set` reads the value
from stdin. Store both values in your secret manager during the rotation. Secret
changes propagate over time; verify application calls, callbacks, and browser
connections between stages.

## Upgrade the runtime

When updating your Atoms PHP packages, upgrade the Worker runtime
to the matching release. For the 0.5.0 release:

```bash
npm exec --yes --package=@atomsphp/runtime-cloudflare@0.5.0 -- \
  atoms-runtime-cloudflare upgrade atoms-worker
cd atoms-worker
npm ci
cd ..
```

Use the version printed by `atoms init` or the version-mismatch error for
your installed CLI.

| Files | What an upgrade does |
|---|---|
| `wrangler.jsonc` | Preserves your configuration. Apply any required changes described in the release notes. |
| Runtime files listed in `atoms-runtime.json` | Replaces them with the release's copies and removes files the release no longer ships. Local edits are overwritten. |

`atoms dev` and `atoms deploy` require an exact version match between the CLI
and `atoms-runtime.json`, checked before building ([ATOMS-E108](/reference/errors/#atoms-e108)).

## Deployment is eventually visible

Deployments take time to reach running Atoms. List the uploaded Worker versions with:

```bash
vendor/bin/atoms status --env production
```

Verify the new Atom methods are available before deploying application code that calls them.

## Deploy from GitHub Actions

The deploy Action installs dependencies in `atoms-worker/`, builds, and deploys using GitHub Secrets:

```yaml
jobs:
  deploy-atoms:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: AtomsPHP/atoms/action@v0.5.0
        with:
          environment: production
          cloudflare-api-token: ${{ secrets.CLOUDFLARE_API_TOKEN }}
          cloudflare-account-id: ${{ vars.CLOUDFLARE_ACCOUNT_ID }}
          shared-secret: ${{ secrets.ATOMS_SHARED_SECRET }}
```

Use a release tag or commit SHA matching your runtime. Set `worker-directory` if your Worker is in another directory.

The `shared-secret` input sets the Worker secret after deployment. It skips
an existing secret by name, even if the supplied value differs. Your
application needs the same value configured through its own deployment.

For rotation, first prepare the application and Worker overlap as described
[above](#rotate-the-shared-secret). Then use `rotate-shared-secret: true` with
`shared-secret` set to the new value and `shared-secret-previous` to the old
value. On a later run, `retire-shared-secret-previous: true`
removes the Worker overlap; remove it from the application separately.
