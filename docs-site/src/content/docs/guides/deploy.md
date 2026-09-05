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
to set up `atoms-worker/`, and run `npm ci` inside it on every fresh checkout —
nothing does that for you, and a missing Wrangler surfaces as
[ATOMS-E073](/reference/errors/#atoms-e073).

## Configure an environment

Deployment targets an environment named in `atoms.json`, and every command on
this page takes `--env <name>` to select one. See
[Configuration](/guides/configuration/#environments) for what an entry holds and
what `--env` resolves from it.

## Authenticate with Cloudflare

On your own machine, authenticate with the installed Wrangler:

```bash
cd atoms-worker
./node_modules/.bin/wrangler login
cd ..
```

When `CLOUDFLARE_API_TOKEN` is unset, Wrangler uses that saved login session.

For headless or scripted deploys, set an API token in the environment instead —
a CI runner has no login session to fall back on:

```bash
export CLOUDFLARE_API_TOKEN='…'
```

A token needs permission to edit Workers Scripts in the target account. Do not
commit it to your repository. Atoms passes both `CLOUDFLARE_API_TOKEN` and
`CLOUDFLARE_ACCOUNT_ID` into the Wrangler child process and nowhere else: never
to a file, a log, or the command line.

## Build and deploy

Check your host adapter is ready first — [Before you
deploy](/concepts/adapters/#before-you-deploy) lists what it must provide.

```bash
vendor/bin/atoms deploy --env production
```

`deploy` validates and bundles your Atom code and dependencies, then deploys the Worker through Wrangler. Use `atoms build` to produce a bundle for inspection or later deployment.

You can validate without a build with `atoms validate`. Pass the `--json` flag for JSON output.

The build resolves the packages listed in
[`atoms-composer.json`](/guides/configuration/#atoms-composerjson) with
`composer install --no-scripts --no-plugins` in an isolated directory, writes
the result back to `atoms-composer.lock` for reproducibility, and caches it
under `.atoms/vendor-cache`. Builds are deterministic and never execute your
code.

## Configure secrets

A deployed Worker needs `ATOMS_SHARED_SECRET` before it will serve anything but
`/healthz`, and it must be set *after* the first deploy because the Worker has
to exist first. If your Atoms call `app()` or `dispatch()`, they also need
`ATOMS_CALLBACK_URL`.

See [Secrets and authentication](/guides/secrets/) for both kinds of secret and
the rotation runbook, and [Callback URL](/guides/callbacks/#callback-url) for
local and deployed callback configuration.

## Verify the deployment

A deploy is not immediately visible everywhere. Cloudflare propagates it over
time, and an Atom already resident in memory keeps running the bundle it
activated with until it next activates. List the uploaded Worker versions with:

```bash
vendor/bin/atoms status --env production
```

Verify the new Atom methods are available before deploying application code that
calls them. To move a Worker back to an earlier version, see
[Rollback](/guides/rollback/).

## Upgrade the runtime

When updating your Atoms PHP packages, upgrade the Worker runtime
to the matching release. For the 0.6.0 release:

```bash
npm exec --yes --package=@atomsphp/runtime-cloudflare@0.6.0 -- \
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

## Deploy from GitHub Actions

The deploy Action installs dependencies in `atoms-worker/`, builds, and deploys using GitHub Secrets:

```yaml
jobs:
  deploy-atoms:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: AtomsPHP/atoms/action@v0.6.0
        with:
          environment: production
          cloudflare-api-token: ${{ secrets.CLOUDFLARE_API_TOKEN }}
          cloudflare-account-id: ${{ vars.CLOUDFLARE_ACCOUNT_ID }}
          shared-secret: ${{ secrets.ATOMS_SHARED_SECRET }}
```

Use a release tag or commit SHA matching your runtime. Set `worker-directory`
if your Worker is in another directory. The
[Action's README](https://github.com/AtomsPHP/atoms/blob/v0.5.0/action/README.md)
documents every input, how to scope the API token, and a troubleshooting table
for the errors a runner hits.

The `shared-secret` input sets the Worker secret after deployment. It skips
an existing secret by name, even if the supplied value differs. Your
application needs the same value configured through its own deployment.

For rotation, first prepare the application and Worker overlap described in
[Rotate the shared secret](/guides/secrets/#rotate-the-shared-secret). Then use `rotate-shared-secret: true` with
`shared-secret` set to the new value and `shared-secret-previous` to the old
value. On a later run, `retire-shared-secret-previous: true`
removes the Worker overlap; remove it from the application separately.
