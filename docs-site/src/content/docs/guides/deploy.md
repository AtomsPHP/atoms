---
title: Deploy
description: Build an Atom bundle and deploy its Worker through Wrangler.
---

Atoms are deployed directly to your Cloudflare account.

## Prepare the Worker

Scaffold `atoms-worker/` once and commit it before deploying locally or from
GitHub Actions. Follow [Initialize the project](/getting-started/install/#initialize-the-project).
After checking out the repository, run `npm ci` inside `atoms-worker/`.
The deploy Action runs this installation step for you.

The CLI resolves Wrangler from `ATOMS_WRANGLER_BIN`, then `atoms-worker/node_modules/.bin/wrangler`, then `PATH`. It never uses `npx` or downloads Wrangler during a deployment.

## Configure an environment

Set the Worker name and endpoint in `atoms.json`, and the account id if your
credentials can access more than one account. Put routes, custom domains,
and runtime settings in `atoms-worker/wrangler.jsonc`.

The CLI's `--env` selects an entry in `atoms.json`. It selects the deployed
Worker by name; it does not select Wrangler's `[env]` configuration. Top-level
settings in `wrangler.jsonc` apply to every deployment. Set `debug_endpoints`
per environment in `atoms.json`, keeping `ATOMS_DEBUG_ENDPOINTS` out of
`wrangler.jsonc`.

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

`deploy` builds and validates your Atom code, includes the packages named in `atoms-composer.json`, and deploys the Worker through Wrangler. Use `atoms build` separately when you want to inspect or save a bundle without deploying it.

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
configuration error. The shared secret must decode to 32 bytes. It is not a
bearer token; application requests use a token derived from it.

The command leaves an existing secret unchanged unless you pass `--force`.
See [Rotate the shared secret](#rotate-the-shared-secret) when changing it.

The Worker environment variable `ATOMS_BEARER_AUTH` controls whether the
Worker checks the `Authorization` bearer your application sends automatically.
Leave it at the default, `required`; set it to `disabled` only when an
authenticating proxy such as Cloudflare Access already sits in front of the
Worker. `ATOMS_SHARED_SECRET` stays mandatory in either posture, and browser
connections are unaffected: they authenticate with a short-lived
[ticket](/guides/websockets-timers/) either way.

If your Atoms call `app()` or `dispatch()`, the deployed Worker also needs
`ATOMS_CALLBACK_URL` set to your application's callback endpoint. Set it as a
Worker variable in `atoms-worker/wrangler.jsonc` when the URL is shared by
your deployments. For different application URLs, set `ATOMS_CALLBACK_URL`
as a Worker secret separately in each deployment (shown in the
[Callbacks guide](/guides/callbacks/#callback-url)). `atoms.json`'s
`callback_url` is read only by `atoms dev`.

Use the Atoms CLI for values your Atom reads through `$this->config()`:

```bash
printf '%s' "$PAYMENTS_API_KEY" | \
  vendor/bin/atoms secrets:set PAYMENTS_API_KEY --env production
```

That stores the configured Worker-prefixed name, normally
`ATOMS_CONFIG_PAYMENTS_API_KEY`. The `secrets:set` command
refuses `ATOMS_SHARED_SECRET` itself - use the dedicated
`shared-secret:set` command instead.

To call a protected route manually, see [The shared secret](/reference/cli/#the-shared-secret)
for a bearer-token example. `/healthz` is public and cannot verify that your
credentials work.

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
connections between stages. The CLI cannot report when every Atom has updated.

## Upgrade the runtime

When updating your Atoms PHP packages, upgrade the committed Worker directory
to the matching release. For the 0.5.0 release:

```bash
npm exec --yes --package=@atomsphp/runtime-cloudflare@0.5.0 -- \
  atoms-runtime-cloudflare upgrade atoms-worker
cd atoms-worker
npm ci
cd ..
git diff -- atoms-worker
```

Review and commit the changes. `atoms init` and the version-mismatch error
print the command for your installed CLI version.

| Files | What an upgrade does |
|---|---|
| `wrangler.jsonc` | Preserves your configuration. Apply any required changes described in the release notes. |
| Runtime files listed in `atoms-runtime.json` | Replaces them with the release's copies and removes files the release no longer ships. Local edits are overwritten. |
| Other files you add | Leaves them alone. |

`atoms dev` and `atoms deploy` require an exact version match between the CLI
and `atoms-runtime.json`, checked before building ([ATOMS-E108](/reference/errors/#atoms-e108)).
An older directory without that stamp needs a fresh scaffold: use `init` in
an empty directory, copy your project settings into its `wrangler.jsonc`, and
commit it as `atoms-worker/`.

## Deployment is eventually visible

A successful upload is not proof that every routed request or already-resident Atom is serving the new version. Cloudflare propagation is eventual, and warm and fresh Atoms can adopt changes in no guaranteed order. Check:

```bash
vendor/bin/atoms status --env production
```

Do not deploy application code that requires new Atom methods until the new Worker version has converged sufficiently for your rollout.

## Deploy from GitHub Actions

The release Action runs `npm ci` in the committed `atoms-worker/` directory, builds, and deploys with credentials supplied by GitHub Secrets:

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

Pin the release tag or a commit SHA matching your runtime. If the Worker directory lives elsewhere, set `worker-directory`; the Action installs its dependencies there. A missing directory fails the workflow, so commit the scaffold before enabling deployment.

The `shared-secret` input sets the Worker secret after deployment. It skips
an existing secret by name, even if the supplied value differs. Your
application needs the same value configured through its own deployment.

For rotation, first prepare the application and Worker overlap as described
[above](#rotate-the-shared-secret). Then use `rotate-shared-secret: true` with
`shared-secret` set to the new value and `shared-secret-previous` to the old
value. The Action writes the current secret first, so do not use this run to
establish the initial overlap. On a later run, `retire-shared-secret-previous: true`
removes the Worker overlap; remove it from the application separately.
