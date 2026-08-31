---
title: Deploy
description: Build an Atom bundle and deploy its Worker through the pinned local Wrangler.
---

Atoms deploys into your Cloudflare account. It does not proxy credentials through an Atoms service.

## Prepare the Worker

Scaffold the co-versioned runtime once and install its lockfile:

```bash
npm exec --yes --package=@atomsphp/runtime-cloudflare@0.1.0 -- \
  atoms-runtime-cloudflare init .atoms/worker
(cd .atoms/worker && npm ci)
```

The CLI resolves Wrangler from `ATOMS_WRANGLER_BIN`, then `.atoms/worker/node_modules/.bin/wrangler`, then `PATH`. It never uses `npx` or downloads Wrangler during a deployment.

## Configure an environment

Add the Worker directory, account id, name, and endpoint to the environment in `atoms.json`. Export credentials for Wrangler:

```bash
export CLOUDFLARE_API_TOKEN='…'
export CLOUDFLARE_ACCOUNT_ID='…'
```

The token needs permission to edit Workers Scripts in the target account. Do not place it in `atoms.json`, command arguments, or committed environment files.

## Validate, build, deploy

```bash
vendor/bin/atoms validate
vendor/bin/atoms build
vendor/bin/atoms deploy --env production
```

`build` discovers the configured Atom tree, validates Atom-side code, vendors approved packages without executing customer code, scopes dependencies, and emits a deterministic bundle plus manifest. `deploy` translates that artifact into the Worker module and invokes the pinned Wrangler.

## Configure callbacks and application secrets

Use Wrangler directly for the operational `ATOMS_CALLBACK_SIGNING_KEY`. Use the Atoms CLI for values your Atom reads through `$this->config()`:

```bash
printf '%s' "$PAYMENTS_API_KEY" | \
  vendor/bin/atoms secrets:set PAYMENTS_API_KEY --env production
```

That stores the configured Worker-prefixed name, normally `ATOMS_CONFIG_PAYMENTS_API_KEY`.

## Deployment is eventually visible

A successful upload is not proof that every routed request or already-resident Atom is serving the new version. Cloudflare propagation is eventual, and warm and fresh Atoms can adopt changes in no guaranteed order. Check:

```bash
vendor/bin/atoms status --env production
```

Do not deploy application code that requires new Atom methods until the new Worker version has converged sufficiently for your rollout.

## Deploy from GitHub Actions

The release Action scaffolds the co-versioned runtime when the default `.atoms/worker` directory is absent, installs its lockfile, builds, and deploys with credentials supplied by GitHub Secrets:

```yaml
jobs:
  deploy-atoms:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: AtomsPHP/atoms/action@v0.1.0
        with:
          environment: production
          cloudflare-api-token: ${{ secrets.CLOUDFLARE_API_TOKEN }}
          cloudflare-account-id: ${{ vars.CLOUDFLARE_ACCOUNT_ID }}
```

Pin the immutable release tag or a commit SHA. When you pass a custom Worker directory, you own its contents and installation instead of the Action scaffolding it.
