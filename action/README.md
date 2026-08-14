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
| `php-version` | No | `8.3` | PHP version used to run the Atoms CLI |
| `node-version` | No | `22` | Node version used to run Wrangler |

## How it works

1. **Masks** the Cloudflare API token so it cannot leak into the log.
2. **Sets up PHP** (default 8.3) with `pdo_sqlite` and `curl`.
3. **Sets up Node** (default 22) — Wrangler is a Node program.
4. **Installs the matching Atoms CLI version** via Composer.
5. **Scaffolds the pinned Worker runtime** at `.atoms/worker` when the default is missing or empty.
6. **Runs `npm ci`** from the runtime's shipped lockfile.
7. **Runs `atoms deploy --env <environment>`** in `working-directory`, with the
   Cloudflare credentials in the environment.

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
| `ATOMS-E072` | Deploy credentials missing | Supply `cloudflare-api-token`; check the secret exists in this repository/environment. |
| `ATOMS-E073` | Wrangler not found | Restore the default runtime scaffold, or ensure a custom Worker contains its locked Wrangler install. |
| `ATOMS-E074` | Wrangler command failed | Read Wrangler's own output; it reports Cloudflare's rejection verbatim. Usually a token missing **Workers Scripts:Edit** on that account. |
| `ATOMS-E075` | Cloudflare account not configured | Supply `cloudflare-account-id`, or set `account_id` for the environment in `atoms.json`. |
| `ATOMS-E076` | Worker directory missing or incomplete | Remove an incomplete default scaffold so the action can recreate it, or repair the caller-managed Worker directory. |
