# Contributing

Atoms is pre-1.0. Release tags and Packagist are the source of truth for what
has been published; public signatures outside the frozen `atoms/core` ABI may
still move between minor releases. Read that as an invitation rather than a
warning: the cost of changing the right thing is still low.

Atoms is open source and self-hosted. You deploy it into your own Cloudflare
account; there is no hosted service and nothing is sold. Contributions are
made under the licences already in the tree — see `LICENSE` and the licensing
section of `cloudflare/README.md`. There is no CLA and no sign-off
requirement.

## Layout

The repository is two halves that meet at the `atoms/core` ABI.

| Path | What it is |
|---|---|
| `packages/{core,client,laravel,symfony,testing,phpstan-rules,cli,database-illuminate}` | The eight PHP packages: the runtime ABI, the monolith-side SDK, the Laravel and Symfony adapters, the test harness, the PHPStan rules, the `atoms` CLI, the Illuminate database bridge. MIT. |
| `action/` | The deploy GitHub Action. |
| `docs/` | `conventions.md` (normative), `integration-plan.md` (rationale), `two-worlds.md`, `errors.md` (the error catalog). |
| `tests/Integration/` | Cross-package tests that no single package owns. |
| `cloudflare/` | The Worker runtime: a PHP 8.3 WebAssembly interpreter parked inside a SQLite-backed Durable Object, its binding spec in `cloudflare/docs/mvp-spec.md`, and the conformance suite. Atoms-authored source here is MIT; upstream components retain their own licenses. |

The two halves have separate toolchains and separate test commands. A change
to the ABI usually touches both — `cloudflare/worker/php/atoms-core/` is a
verbatim, hash-recorded copy of `packages/core/src`. Never edit it in place:
fix `packages/core/` and re-vendor.

Read `cloudflare/docs/mvp-spec.md` before changing the Worker, in particular
its appendix of *measured* platform deviations. `app()`, `dispatch()`,
`broadcast()`, WebSockets and timers/alarms are implemented (M2) — see the
spec's §The callback channel, §The WebSocket seam and §Timers. The one
surface that deliberately stays a typed `AtomsNotSupported` is the
permanently-unsupported corner of the `db()->pdo()` shim
(`cloudflare/worker/php/README.md` §Documented leaks and limits); that is a
restriction, not a milestone stub. Extending the runtime surface further is a
spec change first and a diff second; a half-implementation in a pull request
will be declined.

## The PHP side

PHP 8.3 is the floor. `atoms/core` executes inside the runtime's PHP 8.3
interpreter, so no 8.4-only syntax (no property hooks, no asymmetric
visibility) may appear anywhere in the repository. CI runs the suites on 8.3
and 8.4.

One install at the root wires all eight packages through Composer path
repositories, symlinked. That root install is what you develop and test
against; there is one `vendor/` and the per-package `composer.json` files
exist for standalone consumption, not for working here.

```sh
composer install                     # all eight packages, symlinked
composer test                        # every suite
composer test -- --testsuite=core    # one suite
composer stan                        # PHPStan across packages/*/src
packages/cli/bin/atoms               # run the CLI from source
```

Suite names come from `phpunit.xml.dist`: `core`, `client`, `laravel`,
`symfony`, `testing`, `phpstan-rules`, `cli`, `integration`.

Manifests are validated separately, as CI does it:

```sh
composer validate --strict --no-check-all --no-check-lock
```

Package versions are pinned at `0.1.0` and inter-package constraints at
`^0.1`. Release tooling owns those fields — do not hand-edit them.

## The Worker side

The PHP interpreter is not in this repository. `npm ci` fetches it from
Playground's `@php-wasm/web-8-3` package and the `prepare` lifecycle script
stages it into a gitignored `cloudflare/worker/.php-wasm/`, verifying its
hashes first; see `cloudflare/README.md`.

The conformance suite needs no Cloudflare account, no API token and no
secret. It runs entirely against `wrangler dev` on localhost.

```sh
cd cloudflare/worker
npm ci
npm run bundle
```

Then, in one shell:

```sh
npx wrangler dev
```

and in another:

```sh
cd cloudflare/worker
ATOMS_BASE_URL=http://127.0.0.1:8787 node test/conformance.mjs
```

`wrangler.jsonc` pins dev to `127.0.0.1:8787` and already sets
`ATOMS_DEBUG_ENDPOINTS=1`, which the suite's `debugInfo()` helper needs.
`ATOMS_SHARED_SECRET` is mandatory — `npm run dev:callback`
(`scripts/dev-with-callback.mjs`) generates a fresh per-run secret and passes
it to `wrangler dev`, so local development never runs keyless. Set
`ATOMS_BEARER_AUTH=disabled` to run the local-dev posture with no bearer
check (secret still required, tickets and callbacks still signed) — for
example, to reproduce how a Worker behind an authenticating proxy such as
Cloudflare Access runs in production.

The full suite must pass. Several checks deliberately wait out real Durable
Object eviction, so a full run takes a few minutes. While iterating on
something unrelated to eviction, use the suite's documented `ATOMS_SKIP`
mechanism rather than shortening the wait; a shorter window does not make an
eviction check faster, it makes it assert nothing.

```sh
ATOMS_BASE_URL=http://127.0.0.1:8787 ATOMS_SKIP=12 node test/conformance.mjs
```

Do not commit a green run that skipped checks. `cloudflare/worker/test/README.md`
documents what each check proves and what the remaining environment variables
do. `npm run bundle` regenerates `src/bundle.generated.js` from
`fixtures/counter`; if you change a fixture, rebuild and commit the result.

## Rules that are not up for negotiation in a pull request

These are settled decisions, recorded in `docs/conventions.md` and
`AGENTS.md`. A change that breaks one is a design conversation in an issue,
not a diff.

- **`atoms/core` is a frozen, wire-protocol-grade ABI.** Its public surface is
  what customer code targets and what the runtime executes on the other side
  of the boundary. Add signatures; never change or remove one. `atoms/core`
  depends on nothing framework-ish — `psr/*` interfaces only.
- **Layering is the product.** core ← client ← {laravel, symfony}; core ←
  {testing, phpstan-rules, cli}. The Symfony bundle must never need
  `atoms/laravel`. The CLI and testing packages must never need
  `atoms/client`. Nothing ships in `atoms/laravel` that could live in
  `atoms/client` or `atoms/core`.
- **The error catalog is append-only.**
  `packages/core/resources/errors.json` is the single source of truth for
  `ATOMS-E###` codes. Never renumber, never reuse a retired code, and keep the
  `ErrorCode` enum in sync — a core test enforces it. Every user-facing
  failure carries a code and a catalog fix line.
- **No native `serialize()`/`unserialize()`, anywhere.** Boundary data moves
  as JSON through `Atoms\Serialization\Serializer`.
- **Builds are pure functions of the repository.** `atoms build` must be
  deterministic — identical trees produce byte-identical bundles — and must
  never execute customer code.
- **Tests never hit the network.** HTTP is exercised against an in-memory
  PSR-18 client; the Worker suite talks only to your own `wrangler dev`.

## Pull requests

A good pull request is one a reviewer can evaluate without reconstructing your
reasoning from scratch:

- One concern per PR. A refactor that enables a fix belongs in its own commit,
  ideally its own PR.
- The description says what changed and why, and names the suites you ran
  (`composer test`, `composer stan`, and the conformance suite if you touched
  `cloudflare/`). If you skipped conformance checks, say which and why.
- New behaviour comes with tests in the owning package's suite. A bug fix
  comes with the test that failed before it.
- User-facing failures carry an `ATOMS-E###` code, appended to the catalog.
- Documentation moves with the code. If the change contradicts
  `docs/conventions.md`, either the change is wrong or the document needs
  updating in the same PR — do not leave them disagreeing.
- CI must be green. One workflow (`.github/workflows/ci.yml`) covers both
  halves from one clone: the PHP suites on 8.3 and 8.4, PHPStan, manifest
  validation, and the Worker conformance suite against a local
  `wrangler dev`. No job needs a Cloudflare account or a token, and none may
  be made to need one. A red run is not "flaky" until you have shown it is.

Draft PRs are welcome for work in progress, and an issue first is welcome for
anything large — it is cheaper to discard a paragraph than a week.
