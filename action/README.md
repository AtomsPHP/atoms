# Atoms Deploy Action

A GitHub Actions composite action that deploys an Atoms bundle to a Cloudflare
Worker **in your own Cloudflare account**. There is no Atoms-hosted service: the
action installs the Atoms CLI, which shells out to a pinned, locally installed
Wrangler and talks to Cloudflare's API directly with the credentials you supply.

> No release tag is published for this repository yet. Reference the action as
> `AtomsPHP/atoms/action@main`, or pin it to a commit SHA if you want the
> workflow to be reproducible.

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

In the Cloudflare dashboard: **My Profile → API Tokens → Create Token → Create
Custom Token**.

- **Workers Scripts: Edit** — required; this is what publishes the Worker.
- **Account Settings: Read** — some Wrangler operations (account lookup,
  certain `wrangler deploy` paths) need it. Add it if Wrangler reports an
  authorisation failure.
- Under **Account Resources**, scope the token to the **specific account** you
  deploy into, not "All accounts".

Store it as a repository (or environment) secret, e.g. `CLOUDFLARE_API_TOKEN`.

### Finding the account id

Cloudflare dashboard → **Workers & Pages** → the overview page shows **Account
ID** in the right-hand column. It is not a secret, so a repository variable
(`vars.CLOUDFLARE_ACCOUNT_ID`) is a fine place for it — but the action passes it
the same way as the token, through the step environment.

## Usage

```yaml
- uses: AtomsPHP/atoms/action@main
  with:
    environment: production
    cloudflare-api-token: ${{ secrets.CLOUDFLARE_API_TOKEN }}
    cloudflare-account-id: ${{ vars.CLOUDFLARE_ACCOUNT_ID }}
    worker-directory: .atoms/worker
```

### Inputs

| Input | Required | Default | Description |
|-------|----------|---------|-------------|
| `environment` | Yes | — | Deployment environment (e.g., `staging`, `production`) |
| `cloudflare-api-token` | Yes | — | Cloudflare API token with Workers Scripts:Edit on the target account |
| `cloudflare-account-id` | Yes | — | Cloudflare account id to deploy into |
| `working-directory` | No | `.` | Working directory containing `atoms.json` |
| `worker-directory` | No | — | Worker directory, relative to `working-directory`. Passed to the CLI as `--worker-dir`; if omitted, the CLI resolves it from `atoms.json`. |
| `bundle` | No | — | Path to a prebuilt bundle. If omitted, builds locally. |
| `php-version` | No | `8.3` | PHP version used to run the Atoms CLI |
| `node-version` | No | `20` | Node version used to run Wrangler |

## How it works

1. **Masks** the Cloudflare API token so it cannot leak into the log.
2. **Sets up PHP** (default 8.3) with `pdo_sqlite` and `curl`.
3. **Sets up Node** (default 20) — Wrangler is a Node program.
4. **Installs the Atoms CLI** via Composer.
5. **Runs `npm ci`** in the Worker directory, if `worker-directory` was given.
6. **Runs `atoms deploy --env <environment>`** in `working-directory`, with the
   Cloudflare credentials in the environment.

## The Worker directory

Wrangler is **pinned and locally installed**: the CLI runs
`node_modules/.bin/wrangler` from the Worker directory. Atoms never downloads
Wrangler at deploy time — no `npx` fetch, no unpinned version drifting into a
production deploy. So the Worker directory must have had `npm ci` run in it
before `atoms deploy` executes.

The directory is resolved in this order:

1. the `worker-directory` input (passed to the CLI as `--worker-dir`), then
2. `environments.<env>.worker_dir` in `atoms.json`, defaulting to
   `.atoms/worker`.

The action can only run `npm ci` for case 1, because when the input is empty
the path lives in `atoms.json` and the action does not read that file. Two
supported ways to work:

- **Pass `worker-directory`** (recommended in CI). The action installs its
  dependencies for you. It must be a checked-in Worker project with a
  `package-lock.json`; the step fails with a clear error if there isn't one.
- **Omit it** and take responsibility for the Worker directory yourself — add
  your own `npm ci` step before this action, or restore it from a cache. If you
  omit it and nothing installed the dependencies, the deploy fails with
  **ATOMS-E073 "Wrangler not found"**, whose fix line says to run `npm install`
  in the Worker directory or set `ATOMS_WRANGLER_BIN` to an absolute path.

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
      - uses: AtomsPHP/atoms/action@main
        with:
          environment: production
          cloudflare-api-token: ${{ secrets.CLOUDFLARE_API_TOKEN }}
          cloudflare-account-id: ${{ vars.CLOUDFLARE_ACCOUNT_ID }}
          worker-directory: .atoms/worker
```

### Build locally, then deploy

```yaml
- uses: actions/checkout@v4
- run: atoms build --output my-bundle.tar.gz
- uses: AtomsPHP/atoms/action@main
  with:
    environment: production
    cloudflare-api-token: ${{ secrets.CLOUDFLARE_API_TOKEN }}
    cloudflare-account-id: ${{ vars.CLOUDFLARE_ACCOUNT_ID }}
    worker-directory: .atoms/worker
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
      - uses: AtomsPHP/atoms/action@main
        with:
          environment: staging
          cloudflare-api-token: ${{ secrets.CLOUDFLARE_API_TOKEN }}
          cloudflare-account-id: ${{ vars.CLOUDFLARE_ACCOUNT_ID }}
          worker-directory: .atoms/worker
```

### Bring your own Worker install

```yaml
- uses: actions/checkout@v4
- run: npm ci
  working-directory: .atoms/worker
- uses: AtomsPHP/atoms/action@main
  with:
    environment: production
    cloudflare-api-token: ${{ secrets.CLOUDFLARE_API_TOKEN }}
    cloudflare-account-id: ${{ vars.CLOUDFLARE_ACCOUNT_ID }}
```

## Troubleshooting

| Code | Means | Do |
|------|-------|----|
| `ATOMS-E072` | Deploy credentials missing | Supply `cloudflare-api-token`; check the secret exists in this repository/environment. |
| `ATOMS-E073` | Wrangler not found | Pass `worker-directory` so the action runs `npm ci`, or install the Worker directory yourself first. |
| `ATOMS-E074` | Wrangler command failed | Read Wrangler's own output; it reports Cloudflare's rejection verbatim. Usually a token missing **Workers Scripts:Edit** on that account. |
| `ATOMS-E075` | Cloudflare account not configured | Supply `cloudflare-account-id`, or set `account_id` for the environment in `atoms.json`. |
| `ATOMS-E076` | Worker directory missing or incomplete | Point `worker-directory` (or `worker_dir` in `atoms.json`) at a checkout of the Atoms Cloudflare Worker and run `npm ci` in it. |
