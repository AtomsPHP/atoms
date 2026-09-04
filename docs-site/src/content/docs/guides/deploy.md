---
title: Deploy
description: Build an Atom bundle and deploy its Worker through Wrangler.
---

Atoms are deployed directly to your Cloudflare account.

## Prepare the Worker

Deploying from your own machine needs the Cloudflare Worker runtime scaffolded locally in `.atoms/worker` — follow [Initialize the project](/getting-started/install/#initialize-the-project) on the Install page if you have not already. If you deploy from [GitHub Actions](#deploy-from-github-actions) instead, the Action scaffolds the runtime for you.

The CLI resolves Wrangler from `ATOMS_WRANGLER_BIN`, then `.atoms/worker/node_modules/.bin/wrangler`, then `PATH`. It never uses `npx` or downloads Wrangler during a deployment.

## Configure an environment

Add the Worker directory, account id, name, and endpoint to the environment in `atoms.json`.

On your own machine, authenticate once with `wrangler login`. The Atoms CLI passes nothing when `CLOUDFLARE_API_TOKEN` is unset, so Wrangler uses its own login session and no credential ever passes through the Atoms process.

For headless or scripted deploys, set an API token in the environment instead:

```bash
export CLOUDFLARE_API_TOKEN='…'
```

If you are using a token, it needs permission to edit Workers Scripts in the target account. Do not commit it to your repository.

## Build and deploy

```bash
vendor/bin/atoms build
vendor/bin/atoms deploy --env production
```

`build` discovers the configured Atom tree, validates Atom-side code against the boundary rules, ships the packages named in `atoms-composer.json`, and emits a deterministic bundle plus manifest. `deploy` embeds the bundle into the Worker's JavaScript module and invokes Wrangler to ship everything to Cloudflare.

You can validate without a build with `atoms validate`. Pass the `--json` flag for JSON output.

## Dependencies that ship with the Atom

Packages listed in `atoms-composer.json` are resolved with `composer install --no-scripts --no-plugins` in an isolated directory, written back to `atoms-composer.lock` for reproducibility, and cached under `.atoms/vendor-cache`.

## Configure callbacks and application secrets

The Worker needs the same `ATOMS_SHARED_SECRET` you provide to your application: 32
random bytes, base64-encoded. Set it with the dedicated CLI command, which reads the
value from stdin so it never appears in a command line or a log:

```bash
openssl rand -base64 32 | vendor/bin/atoms shared-secret:set --env production
```

`shared-secret:set` does not overwrite an existing secret unless you pass
`--force`, so running it on every deploy does not mint a new Worker version
each time. When using `--force` to rotate the secret, pass `--previous` to
set `ATOMS_SHARED_SECRET_PREVIOUS` to the old one during the overlap window.
Then run `atoms shared-secret:unset --env production` once every instance on
both sides holds the new secret. If you rotate without `--previous`, every
interaction with existing Workers will fail until they restart.

The Worker environment variable `ATOMS_BEARER_AUTH` controls whether the
Worker checks the `Authorization` bearer your application sends automatically.
Leave it at the default, `required`; set it to `disabled` only when an
authenticating proxy such as Cloudflare Access already sits in front of the
Worker. `ATOMS_SHARED_SECRET` stays mandatory in either posture, and browser
connections are unaffected: they authenticate with a short-lived
[ticket](/guides/websockets-timers/) either way.

If your Atoms call `app()` or `dispatch()`, the deployed Worker also needs
`ATOMS_CALLBACK_URL` set to your application's callback endpoint. Set it as a
Worker variable with Wrangler — `atoms.json`'s `callback_url` is read only by
`atoms dev`. See the [Callbacks guide](/guides/callbacks/#callback-url).

Use the Atoms CLI for values your Atom reads through `$this->config()`:

```bash
printf '%s' "$PAYMENTS_API_KEY" | \
  vendor/bin/atoms secrets:set PAYMENTS_API_KEY --env production
```

That stores the configured Worker-prefixed name, normally
`ATOMS_CONFIG_PAYMENTS_API_KEY`. The `secrets:set` command
refuses `ATOMS_SHARED_SECRET` itself - use the dedicated
`shared-secret:set` command instead.

To curl a deployed Worker without ever pasting the secret into a header,
print the bearer it derives to instead:

```bash
curl -H "Authorization: Bearer $(vendor/bin/atoms token --env production)" \
  https://your-worker.example.workers.dev/healthz
```

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
      - uses: AtomsPHP/atoms/action@v0.4.0
        with:
          environment: production
          cloudflare-api-token: ${{ secrets.CLOUDFLARE_API_TOKEN }}
          cloudflare-account-id: ${{ vars.CLOUDFLARE_ACCOUNT_ID }}
          shared-secret: ${{ secrets.ATOMS_SHARED_SECRET }}
```

Pin the immutable release tag or a commit SHA. When you pass a custom Worker directory, you own its contents and installation instead of the Action scaffolding it.

The `shared-secret` input masks the value and pipes it to `atoms shared-secret:set` after the deploy step, since setting a secret on a Worker that does not exist yet is not possible. The Action is idempotent — it skips the write when the Worker already carries that value — so passing it on every run is safe. Rotating the secret needs two more inputs: `shared-secret-previous` (the old value, kept live during the overlap) with `rotate-shared-secret: true` to apply the new one over an existing value, and, on a later run once every instance holds the new secret, `retire-shared-secret-previous: true` in place of `shared-secret-previous` to close the window.
