---
title: Deploy
description: Build an Atom bundle and deploy its Worker through the pinned local Wrangler.
---

Atoms deploys into your Cloudflare account. It does not proxy credentials through an Atoms service.

## Prepare the Worker

Scaffold the co-versioned runtime once and install its lockfile:

```bash
npm exec --yes --package=@atomsphp/runtime-cloudflare@0.4.0 -- \
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

`build` discovers the configured Atom tree, validates Atom-side code, ships the packages named in `atoms-composer.json`, and emits a deterministic bundle plus manifest, all without executing customer code. `deploy` translates that artifact into the Worker module and invokes the pinned Wrangler.

## Dependencies that ship with the Atom

Packages listed in `atoms-composer.json` are resolved with `composer install --no-scripts --no-plugins` in an isolated directory, written back to `atoms-composer.lock` for reproducibility, and cached under `.atoms/vendor-cache` so repeat builds and `atoms dev` stay offline. The resolved tree ships unprefixed: each package's `.php` and licence files, plus a generated classmap autoloader the runtime requires at activation.

A resolution failure stops the build with `ATOMS-E079`. `atoms build --fast` skips the vendor stage, so it refuses with `ATOMS-E107` while `atoms-composer.json` declares packages — a vendor-less bundle deploys cleanly and then fatals in the guest at the first vendor class.

## Configure callbacks and application secrets

The Worker needs exactly one operational secret, `ATOMS_SHARED_SECRET`: 32
random bytes, base64-encoded, identical on the Worker and the application.
Every credential on that boundary — the `Authorization` bearer, WebSocket
ticket verification, and callback signing — is derived from it. Set it with
the dedicated CLI command, which reads the value from stdin so it never
appears in a command line or a log:

```bash
openssl rand -base64 32 | vendor/bin/atoms shared-secret:set --env production
```

`shared-secret:set` is idempotent: it leaves an existing value alone unless
you pass `--force`, so running it on every deploy does not mint a new Worker
version each time. `--force` is also how you apply a rotation — set the new
value with `--force`, and pass `--previous` to set
`ATOMS_SHARED_SECRET_PREVIOUS` to the old one during the overlap window, then
run `atoms shared-secret:unset --env production` once every instance on both
sides holds the new secret. Without this secret configured, every Worker
route except `GET /healthz` answers `misconfigured` — a first deploy that
skips this step looks healthy but rejects every invocation, callback, and
WebSocket upgrade.

`ATOMS_BEARER_AUTH` (`required`, the default, or `disabled`) is the explicit
posture for the `Authorization` header on invocation and WebSocket routes.
Leave it `required` unless an authenticating proxy such as Cloudflare Access
already sits in front of the Worker — `disabled` turns off only the bearer
comparison; the shared secret stays mandatory and tickets and callbacks stay
signed either way.

Use the Atoms CLI for values your Atom reads through `$this->config()`:

```bash
printf '%s' "$PAYMENTS_API_KEY" | \
  vendor/bin/atoms secrets:set PAYMENTS_API_KEY --env production
```

That stores the configured Worker-prefixed name, normally
`ATOMS_CONFIG_PAYMENTS_API_KEY`. `secrets:set` refuses `ATOMS_SHARED_SECRET`
itself ([ATOMS-E077](/reference/errors/#atoms-e077)): that command's
namespace is exactly what Atom code can read back through `$this->config()`,
and the boundary root must never live there.

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
