# Atoms-on-Cloudflare MVP — what was built

**Date:** 2026-08-04
**Where:** `AtomsPHP/atoms`, everything under `cloudflare/`

> **Note, 2026-08-08.** This is a dated summary of the MVP as it stood on
> 2026-08-04. Two things have changed since and are corrected inline: the tree
> now lives here, in `AtomsPHP/atoms`, rather than in the repository it was
> built in, and the php-wasm runtime is no longer carried in `worker/vendor/`.
> The measured figures below are unchanged.
>
> **Note, 2026-08-12.** M2 landed `app()`, `dispatch()`, WebSockets,
> `broadcast()` and timers/alarms — the "Explicitly stubbed" line and "What's
> next" item 3 below are corrected inline; the conformance suite is now 25
> checks, not 12. See `docs/mvp-spec.md` (§The callback channel, §The
> WebSocket seam, §Timers) for the binding detail; this file's other figures
> (measured latencies, the file table) describe the pre-M2 tree and are left
> as dated history.
>
> **Note, 2026-08-13.** M1 landed the PDO surface honesty pass: a reflection
> tripwire and a differential compatibility harness against a native in-guest
> `pdo_sqlite` (checks 26-28), a generated, drift-checked compatibility matrix
> (`docs/pdo-compatibility.md`, check 30), and a result-set size guard (check
> 29) — the conformance suite is now 30 checks, not 25. "What's next" item 2
> below is corrected inline. See `docs/mvp-spec.md` §Conformance suite and
> `worker/php/README.md` §Documented leaks and limits.

## What was built

An end-to-end working MVP of Atoms on Cloudflare Durable Objects: an unmodified
customer Atom class (frozen `atoms/core` ABI) executing as PHP 8.3 in Wasm
inside a generic SQLite-backed Durable Object, with durable SQL, real
transactions, migrations, lifecycle, and lossless 64-bit integers — validated
by a 12-check conformance suite (now 30 — see the 2026-08-13 note above) that
passes both locally under `wrangler dev` and against the real deployed
Worker.

| Piece | Path (under `cloudflare/`) | What it does |
|---|---|---|
| Binding spec | `docs/mvp-spec.md` | Pins the PHP↔JS wire protocol, DO lifecycle, routes, envelopes, bundle format, conformance checks. Has a **measured-deviations appendix** — read it before trusting assumptions about the platform. |
| Worker (JS host) | `worker/src/` | `index.js` router (`POST /invoke/:type/:id/:method`, healthz, gated debug, bearer auth); `atom-do.js` the one generic `AtomDurableObject` (activation gate, turn mutex, poisoned-residency recovery); `php-host.js` the Asyncify door dispatcher (`!` sync / `~` park); `bridge.js` SQL + transaction state machine + PRAGMA emulation + reserved-table enforcement; `int64.js` codec; `config.js` env-derived settings (no inline capacity constants). |
| PHP guest runtime | `worker/php/runtime/` | The `Atoms\Cf\` prelude: host doors, persistent turn loop (`bootstrap.php`), `BridgeDatabase` (implements `Atoms\Database` over `ctx.storage.sql`), hardened `AtomsPDO` shim, `CfAtomContext`, int64 codec, named-param rewriter, migrations glob shim. |
| Verbatim ABI | `worker/php/atoms-core/` | 22 files **byte-identical** to `packages/core/src` (diff-verified, hashes in `VENDORED-FROM.md`): `Atom`, `AtomContext`, `LifecycleInvoker`, `Database`, `Migrations/*`, `Serialization/*`, etc. |
| Pinned runtime artifact | *(not in the repo — staged into `worker/.php-wasm/`)* | WordPress Playground PHP 8.3 Asyncify build (64-bit ints). Originally carried in `worker/vendor/`; since the 2026-08-08 licensing work `npm ci` fetches it from `@php-wasm/web-8-3` and `scripts/prepare-runtime.mjs` stages and hash-verifies it into a gitignored directory, so no GPL binary is committed. JSPI deliberately dropped — synchronous guest re-entry inside `transactionSync` is Asyncify-only. |
| Fixture app | `worker/fixtures/counter/` | `Counter` and `Vault` Atoms + migrations: warm-residency state, lifecycle rows, tx commit/rollback, int64 matrix, PDO usage — the conformance subject. |
| Bundle builder | `worker/scripts/build-bundle.mjs` | Deterministic assembly of runtime + ABI + fixture into `src/bundle.generated.js` (bundle format v0; stand-in for the future `atoms build`). |
| Conformance | `worker/test/` | 12 checks as of this snapshot (envelope, warm residency, isolation, migrations, tx commit/rollback, exception recovery, int64, reserved tables, turn serialization, eviction/wake) against any base URL; `measure-remote.mjs` for latencies; results in `test/results/remote.json`. M2 added 12 more (callback channel, WebSockets, timers) — see `docs/mvp-spec.md` §Conformance suite. |

**Measured on real Durable Objects:** cold activation ~740ms median, warm turn
~59ms median, post-hibernation wake ~604ms; 7.1MB gzipped upload (Workers Paid
required).

**Explicitly stubbed as of this 2026-08-04 snapshot (typed `AtomsNotSupported`,
never silent):** `app()`, `dispatch()`, `broadcast()`, WebSockets, alarms —
**all five implemented in M2 (2026-08-12)**; see the note at the top of this
file and `docs/mvp-spec.md`.

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
2. Native `pdo_atoms` driver + host ABI as compiled imports — a faster, smaller seam than
   the JSON door, and the end of the hand-written `\PDO` subclass. It does **not** fix the
   int64-read limitation: the precision is lost inside workerd's SQL→JS conversion, before
   any Atoms code of any kind runs, so a compiled driver inherits exactly the same rounded
   double (`docs/mvp-spec.md` Appendix, item 1). The three things that would actually fix it
   are a Cloudflare platform change (BigInt out of `ctx.storage.sql`), a schema-aware
   `CAST(… AS TEXT)` rewrite in the host — available to the JSON door and to a native driver
   equally — or storing wide integers as TEXT in the first place. Today the runtime refuses
   such a read with a typed `int64_precision` error rather than returning a wrong number.
3. ~~Lifecycle completion: WebSockets (Hibernation API), alarms, `app()`/`dispatch()`/`broadcast()`.~~ Landed in M2 (2026-08-12) — see `docs/mvp-spec.md`.
4. Customer toolchain: `atoms build/dev/deploy/status/rollback/secrets` over Wrangler; GitHub Action on Cloudflare credentials. (`build/dev/deploy/status/rollback/secrets` shipped in M3/M4; the GitHub Action is `action/`.)
