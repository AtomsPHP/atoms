# Atoms Deploy Action

A GitHub Actions composite action that deploys an Atoms bundle to a Cloudflare
Worker **in your own Cloudflare account**. There is no Atoms-hosted service: the
action installs the Atoms CLI, which shells out to a pinned, locally installed
Wrangler and talks to Cloudflare's API directly with the credentials you supply.

Use the immutable `AtomsPHP/atoms/action@v0.1.0` release tag, or pin the action
to its full commit SHA for maximum reproducibility.

## Credentials

The action takes two Cloudflare inputs and does exactly one thing with them:
puts them in the deploy step's environment, where Wrangler reads them.

- **`cloudflare-api-token`** → `CLOUDFLARE_API_TOKEN`
- **`cloudflare-account-id`** → `CLOUDFLARE_ACCOUNT_ID`

These are the variable names Wrangler itself reads, deliberately. **Atoms
neither proxies nor retains your credentials.** They are never written to a
file, never printed, never sent anywhere except Cloudflare's own API by
Wrangler running on your runner. The token is passed through `::add-mask::`
before any other step runs, so it is redacted from logs even if a tool echoes
it.

### Creating the API token

**[`docs/cloudflare-toolchain.md` §Getting a token][getting-a-token]** covers
which permissions to grant, how to scope the token, where to find the account
id, and how rotation works. Two things are specific to CI:

- **Store the token as a repository (or environment) secret**, and pass it as
  `cloudflare-api-token`. A GitHub Environment scopes it further, so pull
  requests from forks cannot reach it without approval — see the staging
  example below.
- **The account id** can live in a repository variable
  (`vars.CLOUDFLARE_ACCOUNT_ID`). The action passes it the same way as the
  token, through the step environment.

[getting-a-token]: ../docs/cloudflare-toolchain.md#getting-a-token

## Usage

```yaml
- uses: AtomsPHP/atoms/action@v0.1.0
  with:
    environment: production
    cloudflare-api-token: ${{ secrets.CLOUDFLARE_API_TOKEN }}
    cloudflare-account-id: ${{ vars.CLOUDFLARE_ACCOUNT_ID }}
```

### Inputs

| Input | Required | Default | Description |
|-------|----------|---------|-------------|
| `environment` | Yes | — | Deployment environment (e.g., `staging`, `production`) |
| `cloudflare-api-token` | Yes | — | Cloudflare API token with Workers Scripts:Edit on the target account |
| `cloudflare-account-id` | Yes | — | Cloudflare account id to deploy into |
| `working-directory` | No | `.` | Working directory containing `atoms.json` |
| `worker-directory` | No | `.atoms/worker` | Existing custom Worker directory, relative to `working-directory`. The default is scaffolded automatically when missing or empty. |
| `bundle` | No | — | Path to a prebuilt bundle. If omitted, builds locally. |
| `shared-secret` | No | — | `ATOMS_SHARED_SECRET` for the Worker (32 random bytes, base64). Stored after the deploy, and only when the Worker does not already have one. |
| `shared-secret-previous` | No | — | `ATOMS_SHARED_SECRET_PREVIOUS`, the rotation overlap value. |
| `rotate-shared-secret` | No | `false` | Overwrite the Worker's existing values with the two above. Only the literal `true` enables it. |
| `retire-shared-secret-previous` | No | `false` | Remove `ATOMS_SHARED_SECRET_PREVIOUS`, closing a rotation window. Only the literal `true` enables it. |
| `php-version` | No | `8.3` | PHP version used to run the Atoms CLI |
| `node-version` | No | `22` | Node version used to run Wrangler |

## The shared secret

The Worker cannot serve anything without `ATOMS_SHARED_SECRET`, and a deploy
that ships code without it produces a Worker answering `misconfigured` on every
route **except `GET /healthz`** — a green health check over a broken Worker. Pass
`shared-secret` and the action configures it in the same run:

```yaml
- uses: AtomsPHP/atoms/action@v0
  with:
    environment: production
    cloudflare-api-token: ${{ secrets.CLOUDFLARE_API_TOKEN }}
    cloudflare-account-id: ${{ vars.CLOUDFLARE_ACCOUNT_ID }}
    shared-secret: ${{ secrets.ATOMS_SHARED_SECRET }}
```

Generate the value once with `openssl rand -base64 32`. It goes in three
places: your CI secret store, the Worker (this input), and your **app**
platform's environment under the same name — the last is outside this action's
reach and belongs in your own runbook. The app and Worker values must be
identical; the secret itself never travels over the wire.

Handling, same as the API token: masked with `::add-mask::` before any other
step runs, and piped to the CLI on **stdin**, never an argv a process listing or
log could show. The write happens *after* the deploy step because
`wrangler secret put` needs the Worker to exist, so a first deploy serves
`misconfigured` for the seconds between the two, then heals.

It is idempotent — the write is skipped when the Worker already has the secret,
so running it on every deploy does not mint a Worker version each time. That
also means it will not apply a **changed** value: set `rotate-shared-secret:
true` for that, alongside `shared-secret-previous` for a zero-downtime overlap.

Closing the window is `retire-shared-secret-previous: true` on one later
deploy, in place of `shared-secret-previous`. It removes the overlap key and
succeeds when the key is already gone, so it cannot fail a pipeline that runs
it twice. The overlap key is the only one it removes — `ATOMS_SHARED_SECRET`
has no retire input, because a Worker without it answers `misconfigured` on
every route but `GET /healthz`. Drop the same value from the application side
yourself; nothing here can reach it. See `docs/shared-secret.md` for the full
rotation runbook.

## How it works

1. **Masks** the Cloudflare API token and any shared-secret inputs so they
   cannot leak into the log.
2. **Sets up PHP** (default 8.3) with `pdo_sqlite` and `curl`.
3. **Sets up Node** (default 22) — Wrangler is a Node program.
4. **Installs the matching Atoms CLI version** via Composer.
5. **Scaffolds the pinned Worker runtime** at `.atoms/worker` when the default is missing or empty.
6. **Runs `npm ci`** from the runtime's shipped lockfile.
7. **Runs `atoms deploy --env <environment>`** in `working-directory`, with the
   Cloudflare credentials in the environment.
8. **Configures the shared secret**, when `shared-secret` or
   `shared-secret-previous` is set — after the deploy, because
   `wrangler secret put` needs the Worker to exist.
9. **Retires the previous shared secret**, when
   `retire-shared-secret-previous` is `true`.

## The Worker directory

Wrangler is **pinned and locally installed**: the CLI runs
`node_modules/.bin/wrangler` from the Worker directory. Atoms never downloads
Wrangler at deploy time — no `npx` fetch, no unpinned version drifting into a
production deploy. So the Worker directory must have had `npm ci` run in it
before `atoms deploy` executes.

When `worker-directory` is omitted, the action uses `.atoms/worker`. If that
directory is missing or empty, it installs the version of
`@atomsphp/runtime-cloudflare` stamped into the action release and scaffolds the
Worker before running `npm ci`. The shipped `package-lock.json` pins Wrangler,
php-wasm, and every transitive dependency.

Passing `worker-directory` opts into a caller-managed Worker. The action never
scaffolds or replaces it; the directory must already exist and contain its own
`package-lock.json`. The action still runs its locked `npm ci` before deploy.

## Examples

### Deploy on push to main

```yaml
name: Deploy Atoms
on:
  push:
    branches: [main]

jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: AtomsPHP/atoms/action@v0.1.0
        with:
          environment: production
          cloudflare-api-token: ${{ secrets.CLOUDFLARE_API_TOKEN }}
          cloudflare-account-id: ${{ vars.CLOUDFLARE_ACCOUNT_ID }}
```

### Build locally, then deploy

```yaml
- uses: actions/checkout@v4
- run: atoms build --output my-bundle.tar.gz
- uses: AtomsPHP/atoms/action@v0.1.0
  with:
    environment: production
    cloudflare-api-token: ${{ secrets.CLOUDFLARE_API_TOKEN }}
    cloudflare-account-id: ${{ vars.CLOUDFLARE_ACCOUNT_ID }}
    bundle: my-bundle.tar.gz
```

### Deploy to staging on every pull request

Use a GitHub Environment so the staging token is scoped to that environment and
pull requests from forks cannot reach it without approval.

```yaml
on:
  pull_request:

jobs:
  deploy-staging:
    runs-on: ubuntu-latest
    environment: staging
    steps:
      - uses: actions/checkout@v4
      - uses: AtomsPHP/atoms/action@v0.1.0
        with:
          environment: staging
          cloudflare-api-token: ${{ secrets.CLOUDFLARE_API_TOKEN }}
          cloudflare-account-id: ${{ vars.CLOUDFLARE_ACCOUNT_ID }}
```

### Bring your own Worker install

```yaml
- uses: actions/checkout@v4
- run: npm ci
  working-directory: .atoms/worker
- uses: AtomsPHP/atoms/action@v0.1.0
  with:
    environment: production
    cloudflare-api-token: ${{ secrets.CLOUDFLARE_API_TOKEN }}
    cloudflare-account-id: ${{ vars.CLOUDFLARE_ACCOUNT_ID }}
    worker-directory: .atoms/worker
```

## Troubleshooting

| Code | Means | Do |
|------|-------|----|
| `ATOMS-E072` | Wrangler found no credentials at all | Supply `cloudflare-api-token`; check the secret exists in this repository/environment. A runner has no `wrangler login` session to fall back on, so the token is the only inlet here. |
| `ATOMS-E073` | Wrangler not found | Restore the default runtime scaffold, or ensure a custom Worker contains its locked Wrangler install. |
| `ATOMS-E074` | Wrangler command failed | Read Wrangler's own output; it reports Cloudflare's rejection verbatim. Usually a token missing **Workers Scripts:Edit** on that account. |
| `ATOMS-E075` | Wrangler could not choose between several accounts | Supply `cloudflare-account-id`, or set `account_id` for the environment in `atoms.json`. Only raised when the token reaches more than one account. |
| `ATOMS-E105` | Shared secret missing or malformed | The `shared-secret` input is not 32 bytes of base64. Regenerate with `openssl rand -base64 32`. |
| `ATOMS-E076` | Worker directory missing or incomplete | Remove an incomplete default scaffold so the action can recreate it, or repair the caller-managed Worker directory. |
