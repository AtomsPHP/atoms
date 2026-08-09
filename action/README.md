# Atoms Deploy Action

A GitHub Actions composite action for deploying Atoms bundles to the Atoms platform. Supports both API key and OIDC token exchange authentication.

> **Superseded, 2026-08-08.** This action targets the Fly-era hosted platform
> (`api.atoms.cloud`, OIDC token exchange, per-customer projects), which Atoms
> no longer runs. On Cloudflare you deploy a Worker to your own account with
> Wrangler, and there is no platform API to authenticate against. The action is
> kept here because re-pointing it at Wrangler is planned work, not because it
> works today. Nothing below has been retargeted; no `v1` tag exists on this
> repository. Treat every command in this file as describing the old platform.

## Usage

### With OIDC (recommended)

```yaml
- uses: AtomsPHP/atoms/action@v1
  with:
    environment: production
    permissions:
      id-token: write
```

OIDC automatically exchanges your GitHub Actions identity token for a short-lived deploy token scoped to the specified environment. No long-lived secrets in your CI.

### With API Key

```yaml
- uses: AtomsPHP/atoms/action@v1
  with:
    environment: production
    api-key: ${{ secrets.ATOMS_API_KEY }}
```

### Inputs

| Input | Required | Default | Description |
|-------|----------|---------|-------------|
| `environment` | Yes | — | Deployment environment (e.g., `staging`, `production`) |
| `api-key` | No | — | API key for authentication. If omitted, OIDC token exchange is used. |
| `endpoint` | No | `https://api.atoms.cloud` | Platform API endpoint |
| `working-directory` | No | `.` | Working directory containing `atoms.json` |
| `bundle` | No | — | Path to a prebuilt bundle. If omitted, builds locally. |

## How it works

1. **Sets up PHP 8.3** with required extensions
2. **Installs the Atoms CLI** via Composer
3. **Authenticates** via OIDC token exchange (if no API key provided) or uses the supplied key
4. **Runs `atoms deploy`** with the specified environment

## Authentication modes

### OIDC (Recommended)

The action exchanges your GitHub Actions OIDC token for a short-lived deploy credential scoped to one project and environment. This requires:

- The platform to have OIDC configured (OIDC endpoint at `https://api.atoms.cloud/v1/oidc/token-exchange`)
- Your workflow to include `permissions: id-token: write`

Benefits:
- No long-lived secrets in GitHub
- Credentials expire automatically
- Scoped to one environment per invocation

### API Key

Pass a long-lived API key via `api-key` input. Store it as a GitHub Actions secret.

Note: While supported, this is less secure than OIDC and should only be used if OIDC is not available.

## Examples

### Deploy on push to main

```yaml
name: Deploy Atoms
on:
  push:
    branches: [main]

permissions:
  id-token: write

jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: AtomsPHP/atoms/action@v1
        with:
          environment: production
```

### Build locally, then deploy

```yaml
- uses: actions/checkout@v4
- run: atoms build --output my-bundle.tar.gz
- uses: AtomsPHP/atoms/action@v1
  with:
    environment: production
    bundle: my-bundle.tar.gz
```

### Deploy to staging on every pull request

```yaml
on:
  pull_request:

permissions:
  id-token: write

jobs:
  deploy-staging:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: AtomsPHP/atoms/action@v1
        with:
          environment: staging
```
