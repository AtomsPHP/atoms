# Atoms Framework

The customer-facing SDK and toolchain for the [Atoms](https://atoms.cloud)
platform: stateful, per-entity PHP objects ("Atoms") with their own SQLite
databases, hosted on the Atoms cloud, called from your Laravel or Symfony app
like local objects.

> Status: pre-release (Phase 1 / private beta). APIs may change until 1.0.

This monorepo contains seven packages — see `docs/conventions.md` for the
architecture and `docs/integration-plan.md` for the full design.

| Package | Purpose |
|---|---|
| `atoms/core` | The runtime ABI: `Atom` base class, serialization, migrations, error catalog. Framework-free. |
| `atoms/client` | Framework-agnostic monolith SDK: stub proxies, RPC transport, callback kernel. |
| `atoms/laravel` | Laravel adapter: service provider, `Atoms` facade, queue bridge, Artisan wrappers. |
| `atoms/symfony` | Symfony bundle (internal skeleton — layering test). |
| `atoms/testing` | `AtomHarness` and fakes for fast, infrastructure-free tests. |
| `atoms/phpstan-rules` | Boundary enforcement in your IDE and CI. |
| `atoms/cli` | The `atoms` binary: `init`, `make`, `validate`, `build`, `deploy`, `local`, `ai:install`. |

## Quick start (Laravel)

```bash
composer require atoms/laravel
php artisan atoms:install
php artisan make:atom GameRoom --with-methods --with-migration
atoms validate
atoms deploy --env staging
```

## Development

```bash
composer install
composer test
composer stan
```

## CI/CD

### GitHub Actions

The monorepo includes a CI workflow (`.github/workflows/ci.yml`) that runs on every push to main and pull request:

- **Tests** (`composer test`) across PHP 8.3 and 8.4
- **Static analysis** (`composer stan`) for all packages
- **Lint** (`composer validate`) for root and each package

### Deploy Action

Use the first-party deploy action to deploy Atoms to the platform:

```yaml
jobs:
  deploy:
    runs-on: ubuntu-latest
    permissions:
      id-token: write   # required for OIDC token exchange
    steps:
      - uses: actions/checkout@v4
      - uses: AtomsPHP/atoms-framework/action@v1
        with:
          environment: production
```

The action supports OIDC token exchange (recommended) or API key authentication. See `action/README.md` for full documentation and examples.

### Error Codes

Every error carries a stable code (`ATOMS-E###`) for IDE integration, tooling, and agent automation. See `docs/errors.md` for the complete catalog.
