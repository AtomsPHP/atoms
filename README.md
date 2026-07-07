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
