---
title: Deploy
description: Build an Atom bundle and deploy its Worker to your Cloudflare account.
---

You deploy Atoms to your own Cloudflare account with `vendor/bin/atoms`, which
runs the Wrangler installed in `atoms-worker/`.

## Prepare the Worker

Follow [Initialize the project](/getting-started/install/#initialize-the-project)
to set up `atoms-worker/`, and run `npm ci` inside it after every fresh
checkout.

## Authenticate with Cloudflare

Deploys authenticate with a Cloudflare API token that has permission to edit
Workers Scripts in the target account. In CI, set `CLOUDFLARE_API_TOKEN` as a
job variable from your secret store.

On your own machine, you can log in with the installed Wrangler instead:

```bash
cd atoms-worker
./node_modules/.bin/wrangler login
cd ..
```

Or put the token in the gitignored
[`.env.atoms.<environment>`](/guides/configuration/#envatomsenvironment) file
for the environment you deploy to:

```bash
# .env.atoms.production
CLOUDFLARE_API_TOKEN=…
```

## Build and deploy

Check that your host adapter is ready first; [Before you
deploy](/concepts/adapters/#before-you-deploy) lists what it must provide.
Then deploy an environment:

```bash
vendor/bin/atoms deploy --env production
```

`deploy` validates and bundles your Atom PHP source along with the packages in
[`atoms-composer.json`](/guides/configuration/#atoms-composerjson), then
deploys the Worker through Wrangler. The Worker executes the source using its
PHP interpreter.

Before deployment starts, the CLI prints the settings it will use and [where each one came
from](/guides/environments/#where-values-come-from).

To check your Atom code without deploying, run `atoms validate` (add `--json`
for JSON output). To produce a bundle for inspection or a later deploy, run
`atoms build`.

## Set the shared secret

After the first deploy, set `ATOMS_SHARED_SECRET` on the Worker. The Worker
answers only `/healthz` until it has one. [Secrets and
authentication](/guides/secrets/#the-shared-secret) shows how.

## Verify the deployment

List the Worker's versions with:

```bash
vendor/bin/atoms status --env production
```

Status lists versions, but neither a successful command nor `/healthz` proves
that every Atom runs the new code. Cloudflare [propagates Worker and Durable
Object code eventually](https://developers.cloudflare.com/durable-objects/platform/known-issues/#code-updates):
adjacent versions can coexist, and a newer Worker can call an object running
the prior version.

[Code updates](https://developers.cloudflare.com/durable-objects/concepts/durable-object-lifecycle/#code-updates)
can restart active objects, discarding in-memory state while preserving durable
SQLite data. In-flight calls may fail and WebSockets may disconnect during
[shutdown](https://developers.cloudflare.com/durable-objects/concepts/durable-object-lifecycle/#shutdown-behavior).
Do not blindly retry mutating calls; use application-specific idempotency or
reconciliation, and reconnect sockets.

### Stage application and Atom releases

Deploy new Atom capabilities before application code that calls them, but do
not treat deployment order as a readiness barrier:

1. Add compatible methods, message shapes, and additive schema changes while
   retaining prior behavior.
2. Verify the capability through the application-to-Atom path with a read-only
   or deliberately idempotent check. Keep new calls gated or provide a fallback
   until it is available. A probe proves only the checked path and instance;
   there is no global readiness signal.
3. Release the application, then remove old behavior only when old callers and
   versions no longer need it and you have considered rollback needs.

To restore an earlier version, see [Rollback](/guides/rollback/).

## Upgrade the runtime

When you update the Atoms PHP packages, upgrade `atoms-worker/` to the same
release. For 0.7.0:

```bash
npm exec --yes --package=@atomsphp/runtime-cloudflare@0.7.0 -- \
  atoms-runtime-cloudflare upgrade atoms-worker
cd atoms-worker
npm ci
cd ..
```

`atoms dev` and `atoms deploy` need the CLI and the Worker runtime to be the
same version. `atoms init` and the version-mismatch error both print the
version to use.

| Files | What an upgrade does |
|---|---|
| `wrangler.jsonc` | Keeps your file as it is. The release notes list any change you need to make. |
| Runtime files listed in `atoms-runtime.json` | Replaces them with the release's copies and removes files the release no longer ships. Local edits are overwritten. |

## Deploy from GitHub Actions

The deploy Action installs the Worker's dependencies, builds and deploys:

```yaml
jobs:
  deploy-atoms:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: AtomsPHP/atoms/action@v0.7.0
        with:
          environment: production
          cloudflare-api-token: ${{ secrets.CLOUDFLARE_API_TOKEN }}
          cloudflare-account-id: ${{ vars.CLOUDFLARE_ACCOUNT_ID }}
          shared-secret: ${{ secrets.ATOMS_SHARED_SECRET }}
```

Use the tag or commit SHA that matches your runtime, and set `worker-directory`
if your Worker lives somewhere other than `atoms-worker/`. The [Action's
README](https://github.com/AtomsPHP/atoms/blob/v0.7.0/action/README.md)
documents every input and how to scope the API token.

The `shared-secret` input sets the Worker's shared secret after the deploy
when the Worker doesn't have one yet. Configure the same value in your
application.

To rotate the secret from CI, first prepare your application as described in
[Rotate the shared secret](/guides/secrets/#rotate-the-shared-secret). Then run
the Action with `rotate-shared-secret: true`, `shared-secret` set to the new
value and `shared-secret-previous` set to the old one. On a later run,
`retire-shared-secret-previous: true` removes the old value from the Worker.
