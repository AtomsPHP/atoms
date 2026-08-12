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

final class GameRoom extends Atom
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

**Pre-1.0 and pre-release. Do not build production systems on this yet.**

- The seven PHP packages are **not published on Packagist**. Install is from
  source, out of this repository.
- The Cloudflare runtime is an **MVP**, validated by a 25-check conformance
  suite that passes locally and against a real deployed Worker. It is not a
  managed service; you deploy it to your own account.
- `app()`, `dispatch()`, `broadcast()`, WebSockets and timers/alarms are
  implemented in the Worker runtime (M2). The one remaining typed
  `AtomsNotSupported` surface is the permanently-unsupported corner of the
  `db()->pdo()` shim (see `cloudflare/worker/php/README.md` §Documented leaks
  and limits) — a restriction, not a stub.
- `atoms/client` and `atoms/cli` still speak the earlier hosted-platform HTTP
  contract (`docs/platform/api-contract.md`), which the Cloudflare direction
  supersedes. Rewiring the deploy path is outstanding work.
- APIs may change until 1.0, except the `atoms/core` ABI, which is frozen and
  only grows.

## Layout

| Directory | Contents |
|---|---|
| [`packages/`](packages/) | The seven PHP packages — see the table below |
| [`cloudflare/`](cloudflare/) | The Cloudflare Worker runtime: a PHP interpreter in WebAssembly parked inside a SQLite-backed Durable Object, plus its spec, conformance suite and licence files |
| [`docs/`](docs/) | Architecture and contracts: [`conventions.md`](docs/conventions.md) (normative), [`cloudflare-toolchain.md`](docs/cloudflare-toolchain.md) (deploy, runtime auth, bundles), [`integration-plan.md`](docs/integration-plan.md), [`two-worlds.md`](docs/two-worlds.md), [`errors.md`](docs/errors.md) |
| [`action/`](action/) | The deploy GitHub Action |
| [`tests/`](tests/) | Cross-package integration tests |

| Package | Purpose |
|---|---|
| `atoms/core` | The runtime ABI: `Atom` base class, serialization, migrations, error catalog. Framework-free, PHP 8.3. |
| `atoms/client` | Framework-agnostic monolith SDK: stub proxies, RPC transport, callback kernel. |
| `atoms/laravel` | Laravel adapter: service provider, `Atoms` facade, queue bridge, Artisan wrappers. |
| `atoms/symfony` | Symfony bundle (skeleton — it also exists to prove the layering holds). |
| `atoms/testing` | `AtomHarness` and fakes for fast, infrastructure-free tests. |
| `atoms/phpstan-rules` | Boundary enforcement in your IDE and CI. |
| `atoms/cli` | The `atoms` binary: `init`, `make:atom`, `validate`, `build`, `deploy`, `dev`, `status`, `diff`, `rollback`, `secrets:*`, `ai:install`. |

Every failure in every package carries a stable `ATOMS-E###` code and a fix
line — see [`docs/errors.md`](docs/errors.md).

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

Twelve checks, all of which must pass. Check 12 waits out a real Durable Object
eviction, so the run takes a couple of minutes. **No Cloudflare account is
needed for any of this.**

The PHP interpreter is not in this repository. `npm ci` fetches it from
WordPress Playground's own npm package and stages it into a gitignored
`worker/.php-wasm/` after verifying its hashes. That is a licensing decision as
much as a size one.

One GitHub Actions workflow ([`.github/workflows/ci.yml`](.github/workflows/ci.yml))
covers both halves: the PHP suites on 8.3 and 8.4, static analysis, and the
Worker conformance suite under a local `wrangler dev`.

## Licensing

This repository is mixed-license, and [`LICENSE`](LICENSE) is not the whole
story — it is worth reading this section rather than stopping at the file.

Atoms' own code is **MIT**, granted by [`LICENSE`](LICENSE) and, for
`cloudflare/`, by [`cloudflare/LICENSE-MIT`](cloudflare/LICENSE-MIT).
`cloudflare/` is the exception that matters: it builds against a WordPress
Playground PHP/WebAssembly runtime, so a Worker **assembled** from it is a
combined work under GPL-2.0-or-later — see
[`cloudflare/worker/LICENSE`](cloudflare/worker/LICENSE). That applies to the
artifact, not to this repository: the runtime itself is fetched from npm at
install time and never committed here, so nothing here redistributes GPL code.
Deploying the Worker to infrastructure you control is not distribution, and
GPLv2 is not the AGPL, so running it over a network triggers no source offer.

**None of it relicenses your own PHP application code.** An Atom you write is
carried into the runtime as data for an interpreter to read, not linked into
it, and it targets the MIT-licensed `atoms/core` ABI.

The full statement, including the honest qualifications, is in
[`cloudflare/README.md`](cloudflare/README.md#licensing);
[`cloudflare/THIRD_PARTY_NOTICES.md`](cloudflare/THIRD_PARTY_NOTICES.md)
records what the runtime contains and under which licence.

## More

- [`AGENTS.md`](AGENTS.md) — the workspace guidance (coding agents read this
  first; humans get the map faster from it than from anywhere else)
- [`CONTRIBUTING.md`](CONTRIBUTING.md) — how to build, test and propose changes
- [`SECURITY.md`](SECURITY.md) — reporting a vulnerability
- <https://github.com/AtomsPHP/atoms>
