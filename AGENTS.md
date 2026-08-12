# AGENTS.md

Guidance for coding agents working in this repository. Thin by design: each
subdirectory's own AGENTS.md is binding for work under it; this file holds
only the map and what crosses the boundaries.

## What this repo is

`atoms` is the **Atoms monorepo** — both halves of the product in one tree:
the PHP framework a customer installs into their existing app, and the
Cloudflare Worker runtime their Atoms actually execute inside.

An **Atom** is a persistent PHP object with its own SQLite database. The
framework packages let a Laravel or Symfony monolith call one like a local
object; the Worker hosts it as a PHP 8.3 interpreter compiled to WebAssembly,
parked inside a SQLite-backed Durable Object.

| Directory | What it is | Guidance |
|---|---|---|
| `packages/` | The seven MIT PHP packages: `atoms/{core,client,laravel,symfony,testing,phpstan-rules,cli}` | `docs/conventions.md` (normative) |
| `cloudflare/` | The Worker runtime: `worker/` (host JS, `Atoms\Cf\` guest prelude, fixtures, conformance suite), `docs/` (MVP spec + summary), `corresponding-source/`, the licence files | `cloudflare/README.md`, `cloudflare/docs/mvp-spec.md` |
| `docs/` | Framework docs: `conventions.md`, `cloudflare-toolchain.md`, `integration-plan.md`, `two-worlds.md`, `errors.md`, `platform/api-contract.md` (retired) | — |
| `action/` | The deploy GitHub Action (composite) | `action/README.md` |
| `tests/` | Cross-package integration tests (`Atoms\Tests\Integration\`) | `docs/conventions.md` |

Assembled 2026-08-08 from two predecessor repositories, under deliberately
different rules. The framework half was imported **with** its full history.
`cloudflare/` was imported **without** history, as files rather than commits:
the repository it came from has a GPL php-wasm binary reachable in its history,
and `git clone` delivers history rather than the tip, so inheriting it would
have republished the binary. **Never graft that history on**, however
convenient it looks.

An earlier, differently-shaped platform preceded the Cloudflare runtime — an
edge router, a central control plane, a durability bus, a process-per-Atom
runtime. None of it is in this repository, and Cloudflare supplies most of what
it existed to provide. Treat references to it in older documents as history,
not direction.

## Read before non-trivial work

1. `docs/conventions.md` — cross-package contracts, the frozen `atoms/core`
   ABI, error-catalog rules, layering. **Normative.** If code and this file
   disagree, the code is wrong.
2. `docs/integration-plan.md` — the design rationale (why seven packages, the
   two-worlds model, the extraction pipeline).
3. `cloudflare/docs/mvp-spec.md` — the binding spec for the Worker: PHP↔JS
   wire protocol, DO lifecycle, routes, envelopes, bundle format, and an
   appendix of **measured** platform deviations. Read the appendix before
   assuming anything about workerd.
4. `docs/cloudflare-toolchain.md` — **normative** for how the two halves meet:
   the runtime auth decision (`atoms/client` calls the Worker's prefixless,
   single-tenant `/invoke/...`), how a PHP CLI drives a pinned Wrangler, and
   the bundle bridge between `atoms build` and the Worker. M4 inherits all
   three decisions, so read it before changing any of them.
5. `docs/platform/api-contract.md` — **retired**, history only. It is the
   Fly-era platform HTTP contract v1 (`/v1/{customer}/invoke/...`, anycast
   edge, Atoms-issued API keys). Nothing implements it since M3. Do not cite
   it as the transport.

## Hard rules — the PHP packages

- **`atoms/core` is the runtime ABI.** Its public surface is wire-protocol
  grade: never change an existing signature, only add. It depends on nothing
  framework-ish (`psr/*` interfaces only). It must run on PHP 8.3.
- **Layering is the product.** core ← client ← {laravel, symfony};
  core ← {testing, phpstan-rules, cli}. The Symfony bundle must never need
  `atoms/laravel`; the CLI and testing packages must never need
  `atoms/client`. Nothing ships in `atoms/laravel` that could live in
  `atoms/client` or `atoms/core`.
- **The error catalog is the single source of truth.**
  `packages/core/resources/errors.json` — every user-facing failure in every
  package carries its `ATOMS-E###` code and catalog fix line. Append-only;
  never renumber or repoint an existing code; keep the `ErrorCode` enum in sync
  (a core test enforces it). Rewording an existing code's message/fix **is**
  allowed — see `docs/conventions.md` §Error catalog.
- **No native `serialize()`/`unserialize()`** anywhere in the codebase.
  Boundary data moves as JSON through `Atoms\Serialization\Serializer`.
- **Builds are pure functions of the repo.** `atoms build` must be
  deterministic (byte-identical bundles from identical trees) and must never
  execute customer code.
- **The CLI never fetches a toolchain, and never holds a credential.**
  `deploy`/`status`/`rollback`/`secrets` run a Wrangler that is already on the
  machine — never `npx`, which would defeat the pin in the Worker project's
  lockfile. Cloudflare credentials go into the Wrangler child process's
  environment and nowhere else: no file, no log, no echo. The single exception
  is `action/action.yml`'s `::add-mask::` step, which echoes the token to
  register it for redaction — GitHub has no non-echoing form of that command,
  and an action input is not masked automatically unless it came from
  `secrets.*`. Masking is what keeps the rest of the rule true in a log.
- Package versions are pinned `0.1.0` and managed by release tooling — don't
  hand-edit them. Root `composer.json` wires the packages via path
  repositories; one root `composer install`, one root `vendor/`.

## Hard rules — `cloudflare/`

- **The php-wasm runtime binary is never committed.** `npm ci` fetches it from
  `@php-wasm/web-8-3`, and `scripts/prepare-runtime.mjs` stages it into a
  gitignored `worker/.php-wasm/` after verifying the upstream size and SHA-256
  of both the wasm and the JS glue. Committing it would make this repository
  redistribute GPL code and re-attach every obligation the licensing work
  removed. Never soften or skip the hash check either — a mismatch is a real
  finding, not CI noise.
- **The licence shape is settled; do not re-litigate it in code.** Atoms' own
  source is MIT by grant file (`LICENSE`, `cloudflare/LICENSE-MIT`); a Worker
  you *assemble* is GPL-2.0-or-later because of what it links at build time
  (`cloudflare/worker/LICENSE`); end-user PHP application code is not
  relicensed by any of it. `cloudflare/README.md` §Licensing and
  `cloudflare/THIRD_PARTY_NOTICES.md` are the full account — cross-reference
  them rather than restating them anywhere new.
- **No per-file SPDX headers, and no `LICENSES/` directory.** Both were tried
  and deliberately removed. The grant files are the grant. The one exception
  is `worker/package.json`, which declares `GPL-2.0-or-later` because a
  manifest describes the package *as assembled*.
- **`app()`, `dispatch()`, `broadcast()`, WebSockets and timers/alarms are
  implemented (M2)**, over a signed callback channel, the Hibernation API and
  a multiplexed Durable Object alarm respectively — see
  `cloudflare/docs/mvp-spec.md` §The callback channel, §The WebSocket seam and
  §Timers. `AtomsNotSupported` (`worker/php/runtime/`) now legitimately
  remains only on the permanently-unsupported corner of the PDO shim
  (`AtomsPDO.php`/`AtomsStatement.php` — see `worker/php/README.md`
  §Documented leaks and limits); that restriction is not a stub awaiting a
  later milestone. Adding to the runtime surface beyond what M2 landed is
  still a spec change first — do not "fix" a gap by inventing a
  half-implementation.
- **No capacity numbers in code.** Every TTL, cap, deadline, limit and poll
  interval comes from an environment variable with a default, resolved in
  `worker/src/config.js` and nowhere else.
- `worker/php/atoms-core/` is a **verbatim** copy of `packages/core/src`,
  hash-recorded in its `VENDORED-FROM.md`. Never edit it in place — fix
  `packages/core/` and re-vendor.

## Cross-cutting rules

- **One CI workflow tests everything, from one clone.** `.github/workflows/ci.yml`
  runs the PHP suites (`composer test` on 8.3 and 8.4), `composer stan`,
  manifest lint, *and* the Worker's 25-check conformance suite under a
  local `wrangler dev`. Keep that true: a change to either half must leave the
  whole workflow green, and no job may need a Cloudflare account, an API
  token, or a cross-repo fetch of any kind.
- **The conformance suite is the acceptance gate for `cloudflare/`.** Its
  assertions are not edited to accommodate an implementation; the only legal
  edits are ADDITIVE ones that make a check assert more. Checks 12, 21, 24 and
  25 each wait out a real eviction, so the run takes minutes — shortening
  `ATOMS_EVICTION_WAIT_MS` does not make it faster, it makes it assert nothing.
- **Tests never hit the network.** HTTP is tested against an in-memory PSR-18
  fake; SQLite tests use `:memory:` or a temp dir. The Worker suite talks only
  to a worker you started locally.
- Agent guidance lives in `AGENTS.md` files; every `CLAUDE.md` here is a
  symlink to its sibling `AGENTS.md`. Keep the convention when adding
  directories.

## Common commands

```sh
# PHP framework — from the repo root
composer install                     # one root install for all packages
composer test                        # all test suites
composer test -- --testsuite=core    # one package's suite
composer stan                        # phpstan across all packages/*/src
packages/cli/bin/atoms               # run the CLI from source
packages/cli/bin/atoms dev           # build + `wrangler dev`; no Cloudflare account needed

# Cloudflare Worker — from cloudflare/worker
npm ci                               # installs deps and stages .php-wasm/
npm run prepare-runtime              # stage the runtime unconditionally
npm run bundle                       # build src/bundle.generated.js from the conformance fixture
npm run bundle:cli -- B M src/bundle.generated.js   # ...or from an `atoms build` bundle + manifest
npx wrangler dev                     # serves on 127.0.0.1:8787 (wrangler.jsonc)
npm run dev:callback                 # same, plus a per-run callback keypair wired in
                                      # (ATOMS_CALLBACK_URL/ATOMS_CALLBACK_SIGNING_KEY), for checks 13-17
ATOMS_BASE_URL=http://127.0.0.1:8787 node test/conformance.mjs
```

The conformance runner reads `ATOMS_BASE_URL` (required), `ATOMS_APP_KEY`
(optional bearer token; unset means auth is off), `ATOMS_EVICTION_WAIT_MS`
(default 12500) and `ATOMS_SKIP` (comma-separated check numbers). Debug
endpoints, which checks 5/10/12 need, are gated on the worker's own
`ATOMS_DEBUG_ENDPOINTS` var, already set in `wrangler.jsonc`. Checks 13-17
(the callback channel) additionally need `ATOMS_CALLBACK_PORT` (the runner's
own loopback listener port; default is the port `dev-with-callback.mjs`
recorded in the gitignored `test/.callback-key.json`) and, for check 15,
`ATOMS_TURN_DEADLINE_MS` set to the same value the Worker was started with —
both absent just skips those checks rather than failing the run — unless
`ATOMS_REQUIRE_CALLBACK_CHECKS=1` (which CI sets), which turns those skips into
failures so a broken harness cannot quietly delete five checks. No Cloudflare
account is needed for any of it.
