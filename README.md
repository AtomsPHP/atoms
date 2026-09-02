# Atoms

Put an **Atom** — a persistent PHP object with its own SQLite database — into
the PHP application you already have.

You write an ordinary PHP class. Your Laravel or Symfony monolith calls it like
a local object. It runs somewhere else: inside a Cloudflare Durable Object on
your own Cloudflare account, as PHP 8.3 compiled to WebAssembly, with its
SQLite database living beside it. One Atom per entity — a game room, a
document, a chat thread, a tenant — each single-threaded, each durable, each
addressable by id.

```php
namespace App\Atoms;

use Atoms\Atom;

class GameRoom extends Atom
{
    public function join(string $player): int
    {
        $this->db()->execute('INSERT INTO players (name) VALUES (?)', [$player]);

        return (int) $this->db()->query('SELECT COUNT(*) c FROM players')[0]['c'];
    }
}
```

```php
$count = Atoms::get(GameRoom::class, 'room-42')->join('ada');
```

No connection pool, no cache invalidation, no distributed lock. The object *is*
the consistency boundary.

## Status

**Pre-1.0. Releases are coordinated across the PHP packages, Worker runtime,
and deploy Action.**

- `release/manifest.json` is the version source inside the repository; release
  tags are the source of truth for registry availability. Applications install
  the eight packages from Packagist and the matching Worker template from npm.
- The Cloudflare runtime is validated by its conformance suite locally and
  against a real deployed Worker. It is not a managed service; you deploy it
  to your own account.
- `app()`, `dispatch()`, `broadcast()`, WebSockets and timers/alarms are
  implemented in the Worker runtime. The one remaining typed
  `AtomsNotSupported` surface is the permanently-unsupported corner of the
  `db()->pdo()` shim (see `cloudflare/worker/php/README.md` §Documented leaks
  and limits) — a restriction, not a stub.
- `atoms/client`, `atoms/cli`, the Laravel adapter, the Symfony bundle, and the
  plain-PHP integration all target the self-hosted Cloudflare Worker contract.
- Every public API may change until 1.0 except `atoms/core`, which is frozen
  and only grows.

## Layout

| Directory | Contents |
|---|---|
| [`packages/`](packages/) | The eight PHP packages — see the table below |
| [`cloudflare/`](cloudflare/) | The Cloudflare Worker runtime: a PHP interpreter in WebAssembly parked inside a SQLite-backed Durable Object, plus its spec, conformance suite and licence files |
| [`docs/`](docs/) | Architecture and contracts: [`conventions.md`](docs/conventions.md) (normative), [`adapters.md`](docs/adapters.md) (the contracts each host adapter supplies and the conformance suite), [`cloudflare-toolchain.md`](docs/cloudflare-toolchain.md) (deploy, runtime auth, bundles), [`two-worlds.md`](docs/two-worlds.md), [`errors.md`](docs/errors.md) |
| [`action/`](action/) | The deploy GitHub Action |
| [`tests/`](tests/) | Cross-package integration tests |
| [`site/`](site/) | The public marketing site (Astro) |

| Package | Purpose |
|---|---|
| `atoms/core` | The frozen runtime API: `Atom` base class, serialization, migrations, error catalog. Framework-free, PHP 8.3. |
| `atoms/client` | Framework-agnostic monolith SDK: stub proxies, RPC transport, callback kernel. |
| `atoms/laravel` | Laravel adapter: service provider, `Atoms` facade, queue bridge, Artisan wrappers. |
| `atoms/symfony` | Supported Symfony bundle: DI, route loader, Messenger bridge, and console wrappers. |
| `atoms/testing` | `AtomHarness` and fakes for fast, infrastructure-free tests. |
| `atoms/phpstan-rules` | Boundary enforcement in your IDE and CI. |
| `atoms/cli` | The `atoms` binary: `init`, `make:atom`, `validate`, `build`, `deploy`, `dev`, `status`, `diff`, `rollback`, `secrets:*`, `ai:install`. |
| `atoms/database-illuminate` | The Laravel query builder and Eloquent models against an Atom's own SQLite database (optional, ships inside the Atom bundle via `atoms-composer.json`). |

Every failure in every package carries a stable `ATOMS-E###` code and a fix
line — see [`docs/errors.md`](docs/errors.md).

## Install

Laravel applications install the adapter and its CLI, then scaffold the
release-matched Worker project:

```sh
composer require atoms/laravel:^0.5
composer require --dev atoms/cli:^0.5 atoms/phpstan-rules:^0.5 atoms/testing:^0.5
php artisan atoms:install
npm exec --yes --package=@atomsphp/runtime-cloudflare@0.5.0 -- \
  atoms-runtime-cloudflare init .atoms/worker
cd .atoms/worker && npm ci
```

The complete Laravel, Symfony and plain-PHP paths live at
[docs.atomsphp.dev](https://docs.atomsphp.dev). The maintained Laravel example
is in [`examples/laravel/`](examples/laravel/).

## Working in this repository

The PHP half, from the repo root:

```sh
composer install                     # one root install for all packages
composer test                        # all suites
composer test -- --testsuite=core    # one package
composer stan                        # phpstan across packages/*/src
packages/cli/bin/atoms               # run the CLI from source
```

The Cloudflare half, from `cloudflare/worker`:

```sh
npm ci                               # deps, and stages the PHP runtime
npm run bundle                       # build the worker bundle
npx wrangler dev                     # serves on 127.0.0.1:8787

# in another terminal:
ATOMS_BASE_URL=http://127.0.0.1:8787 node test/conformance.mjs
```

The full conformance suite must pass. Several checks wait out real Durable
Object eviction, so the run takes a few minutes. **No Cloudflare account is
needed for any of this.**

The PHP interpreter is not in this repository. `npm ci` fetches it from
WordPress Playground's own npm package and stages it into a gitignored
`worker/.php-wasm/` after verifying its hashes.

One GitHub Actions workflow ([`.github/workflows/ci.yml`](.github/workflows/ci.yml))
covers both halves: the PHP suites on 8.3 and 8.4, static analysis, and the
Worker conformance suite under a local `wrangler dev`.

## Licensing and third-party components

Atoms-authored source is **MIT**, granted by [`LICENSE`](LICENSE) and
[`cloudflare/LICENSE-MIT`](cloudflare/LICENSE-MIT). The PHP/WebAssembly
interpreter is fetched from WordPress Playground's npm package rather than
committed here. Its component licenses, exact version, hashes, and source
provenance are recorded in
[`cloudflare/THIRD_PARTY_NOTICES.md`](cloudflare/THIRD_PARTY_NOTICES.md) and
[`cloudflare/corresponding-source/`](cloudflare/corresponding-source/).

## More

- [`AGENTS.md`](AGENTS.md) — the workspace guidance (coding agents read this
  first; humans get the map faster from it than from anywhere else)
- [`CONTRIBUTING.md`](CONTRIBUTING.md) — how to build, test and propose changes
- [`SECURITY.md`](SECURITY.md) — reporting a vulnerability
- <https://github.com/AtomsPHP/atoms>
