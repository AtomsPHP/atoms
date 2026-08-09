# Atoms-on-Cloudflare MVP — what was built

**Date:** 2026-08-04
**Where:** `AtomsPHP/atoms`, everything under `cloudflare/`

> **Note, 2026-08-08.** This is a dated summary of the MVP as it stood on
> 2026-08-04. Two things have changed since and are corrected inline: the tree
> now lives here, in `AtomsPHP/atoms`, rather than in the repository it was
> built in, and the php-wasm runtime is no longer carried in `worker/vendor/`.
> The measured figures below are unchanged.

## What was built

An end-to-end working MVP of Atoms on Cloudflare Durable Objects: an unmodified
customer Atom class (frozen `atoms/core` ABI) executing as PHP 8.3 in Wasm
inside a generic SQLite-backed Durable Object, with durable SQL, real
transactions, migrations, lifecycle, and lossless 64-bit integers — validated
by a 12-check conformance suite that passes both locally under `wrangler dev`
and against the real deployed Worker.

| Piece | Path (under `cloudflare/`) | What it does |
|---|---|---|
| Binding spec | `docs/mvp-spec.md` | Pins the PHP↔JS wire protocol, DO lifecycle, routes, envelopes, bundle format, conformance checks. Has a **measured-deviations appendix** — read it before trusting assumptions about the platform. |
| Worker (JS host) | `worker/src/` | `index.js` router (`POST /invoke/:type/:id/:method`, healthz, gated debug, bearer auth); `atom-do.js` the one generic `AtomDurableObject` (activation gate, turn mutex, poisoned-residency recovery); `php-host.js` the Asyncify door dispatcher (`!` sync / `~` park); `bridge.js` SQL + transaction state machine + PRAGMA emulation + reserved-table enforcement; `int64.js` codec; `config.js` env-derived settings (no inline capacity constants). |
| PHP guest runtime | `worker/php/runtime/` | The `Atoms\Cf\` prelude: host doors, persistent turn loop (`bootstrap.php`), `BridgeDatabase` (implements `Atoms\Database` over `ctx.storage.sql`), hardened `AtomsPDO` shim, `CfAtomContext`, int64 codec, named-param rewriter, migrations glob shim. |
| Verbatim ABI | `worker/php/atoms-core/` | 22 files **byte-identical** to `packages/core/src` (diff-verified, hashes in `VENDORED-FROM.md`): `Atom`, `AtomContext`, `LifecycleInvoker`, `Database`, `Migrations/*`, `Serialization/*`, etc. |
| Pinned runtime artifact | *(not in the repo — staged into `worker/.php-wasm/`)* | WordPress Playground PHP 8.3 Asyncify build (64-bit ints). Originally carried in `worker/vendor/`; since the 2026-08-08 licensing work `npm ci` fetches it from `@php-wasm/web-8-3` and `scripts/prepare-runtime.mjs` stages and hash-verifies it into a gitignored directory, so no GPL binary is committed. JSPI deliberately dropped — synchronous guest re-entry inside `transactionSync` is Asyncify-only. |
| Fixture app | `worker/fixtures/counter/` | `Counter` and `Vault` Atoms + migrations: warm-residency state, lifecycle rows, tx commit/rollback, int64 matrix, PDO usage — the conformance subject. |
| Bundle builder | `worker/scripts/build-bundle.mjs` | Deterministic assembly of runtime + ABI + fixture into `src/bundle.generated.js` (bundle format v0; stand-in for the future `atoms build`). |
| Conformance | `worker/test/` | 12 checks (envelope, warm residency, isolation, migrations, tx commit/rollback, exception recovery, int64, reserved tables, turn serialization, eviction/wake) against any base URL; `measure-remote.mjs` for latencies; results in `test/results/remote.json`. |

**Measured on real Durable Objects:** cold activation ~740ms median, warm turn
~59ms median, post-hibernation wake ~604ms; 7.1MB gzipped upload (Workers Paid
required).

**Explicitly stubbed (typed `AtomsNotSupported`, never silent):** `app()`,
`dispatch()`, `broadcast()`, WebSockets, alarms.

**Platform facts discovered the hard way** (details in the spec appendix):
the guest clock is frozen inside a turn on deployed workerd (any sleeping or
elapsed-time customer code is a residency-killing hang); `ctx.storage.sql`
speaks no BigInt (wide-int writes are inlined exactly; reads >2^53 are refused
as `int64_precision` unless `CAST(... AS TEXT)`); `GLOB_BRACE` doesn't exist in
this php-wasm build (migration discovery is shimmed).

## How prior work maps onto it

The MVP replaced an earlier platform of a very different shape: an edge
router, a central control plane, a message-bus durability layer and a
process-per-Atom runtime. Cloudflare supplies what most of that existed to
provide — placement, single-activation routing, durability, hibernation and
scaling — so what survives is the product surface: PHP execution, the frozen
ABI, framework semantics and deployment ergonomics.

The component-by-component mapping is internal history about systems that are
not in this repository, and is kept with the project's internal records rather
than here. One part of it is worth stating publicly, because it is a claim
about code that *is* here:

### The customer SDK (`packages/`) → unchanged, and proven portable

This is the payoff of the frozen-ABI discipline: `packages/core` runs
**verbatim** inside the guest — the MVP vendored it byte-identical and the
real `Migrator`, `Serializer`, and `LifecycleInvoker` all work unmodified on
php-wasm (one namespaced `glob()` shim outside their files). Atoms written
against the ABI are source-compatible across hosts. Still pending on this
side (post-MVP): pointing `packages/cli` (`atoms build/deploy/status`) and the
GitHub Action at Wrangler + customer Cloudflare credentials, and the
`packages/client` direct-to-Worker endpoint/auth story.

## What's next

1. Owned php-wasm build (hermetic, reproducible, trimmed extensions) replacing the Playground artifact.
2. Native `pdo_atoms` driver + host ABI as compiled imports — this is also what genuinely fixes the int64-read limitation.
3. Lifecycle completion: WebSockets (Hibernation API), alarms, `app()`/`dispatch()`/`broadcast()`.
4. Customer toolchain: `atoms build/dev/deploy/status/rollback/secrets` over Wrangler; GitHub Action on Cloudflare credentials.
