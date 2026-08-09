# Atoms-on-Cloudflare MVP — what was built and how it maps to prior work

**Date:** 2026-08-04
**Where:** `AtomsPHP/atoms`, everything under `cloudflare/`

> **Note, 2026-08-08.** This is a dated summary of the MVP as it stood on
> 2026-08-04, kept for the mapping in "How this maps to prior work" below. Two
> things have changed since and are corrected inline: the tree is committed and
> now lives in the public monorepo `AtomsPHP/atoms` rather than on an
> uncommitted `cloudflare-mvp` branch of the private `atoms-core` repo, and the
> php-wasm runtime is no longer carried in `worker/vendor/`. The measured
> figures below are unchanged.

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
required, as the production plan predicted).

**Explicitly stubbed (typed `AtomsNotSupported`, never silent):** `app()`,
`dispatch()`, `broadcast()`, WebSockets, alarms.

**Platform facts discovered the hard way** (details in the spec appendix):
the guest clock is frozen inside a turn on deployed workerd (any sleeping or
elapsed-time customer code is a residency-killing hang); `ctx.storage.sql`
speaks no BigInt (wide-int writes are inlined exactly; reads >2^53 are refused
as `int64_precision` unless `CAST(... AS TEXT)`); `GLOB_BRACE` doesn't exist in
this php-wasm build (migration discovery is shimmed).

## How prior projects map onto it

The strategic shift: Cloudflare now supplies placement, single-activation
routing, durability, hibernation, and scaling — the things most of the old
platform existed to provide. What survives is the product surface: PHP
execution, the frozen ABI, framework semantics, and deployment ergonomics.

### `atoms-core/router/` (Go edge router) → mostly absorbed by Cloudflare

| Router responsibility | Where it went |
|---|---|
| Edge routing, invoke endpoint | `worker/src/index.js` (~200 lines of JS; same `/invoke/{type}/{id}/{method}` + `{"args":[...]}` wire shape, minus the `/v1/{customer}` prefix — single-tenant Worker) |
| Single-activation guarantee, placement | Durable Object identity (`idFromName(type\nid)`) — Cloudflare's problem now |
| Turn-based concurrency | DO event delivery + the turn mutex in `atom-do.js` |
| Machine contract, seam tests | Superseded; the contract is now `docs/mvp-spec.md`, enforced by the conformance suite |

### `atoms-core/control-plane/` (Laravel Directory, Build/Deploy, atomsctl) → eliminated

Directory leases and epochs (fencing) → DO single-instance semantics.
Central Build/Deploy service and S3 bundle store → immutable Worker versions
deployed by the customer's own Wrangler. Platform API keys/OIDC → customer's
own Cloudflare API token. `atomsctl` fleet ops → Wrangler/Cloudflare
operations. Nothing from the control plane sits in the request or deploy path.

### `atoms-core/runtime/` (Track R, Amp v3 process) → re-expressed as the DO-resident PHP loop

The Amp process's job — one resident PHP object per Atom, activation,
turn dispatch, durability — is now `worker/php/runtime/bootstrap.php` parked
inside one `php.run()` per residency. Its design decisions carried over
directly: `onActivation()` on every activation after migrations,
best-effort deactivation, turn output released only after durable success
(the DO output gate provides this), and the `RuntimeAtomContext` shape
(`CfAtomContext` is its Cloudflare sibling). Its NATS WAL-shipping phase 2
is **gone entirely** — DO SQLite is already durable, so there is no shipper,
no compactor, no per-customer NATS accounts, no tombstone cold-deletion job.

### `mvp-load-test/` (density + NATS durability benchmark) → retired as a validation vehicle

It existed to prove the Fly density/durability economics. The equivalent
questions on Cloudflare (isolate memory, active-object density, real commit
latency) are answered by `test/measure-remote.mjs` + the production plan's
phase-6 remote conformance, not by that harness.

### The customer SDK (`packages/`, then a separate repo) → unchanged, and proven portable

This is the payoff of the frozen-ABI discipline: `packages/core` runs
**verbatim** inside the guest — the MVP vendored it byte-identical and the
real `Migrator`, `Serializer`, and `LifecycleInvoker` all work unmodified on
php-wasm (one namespaced `glob()` shim outside their files). Customer Atoms
written for the Fly platform are source-compatible. Still pending on this
side (post-MVP): pointing `packages/cli` (`atoms build/deploy/status`) and the
GitHub Action at Wrangler + customer Cloudflare credentials, and the
`packages/client` direct-to-Worker endpoint/auth story.

### `spikes/` → consumed

`spikes/wasm-php/` established PHP-in-Wasm viability and the interpreter tax;
`spikes/do-php/` (phases 1–3) discovered the door/park mechanism, the
`transactionSync` re-entry trick, and the Asyncify-only constraint. The MVP
ported `phase2-do`'s `php-host.js`, PDO shim, and loop shape nearly verbatim,
then hardened them. The spikes are discovery artifacts now; the production
plan (`spikes/do-php/production-plan.md`) remains the direction authority.

### Legacy status

Per the production plan: `router/`, `control-plane/`, `runtime/`, and the NATS
deploy code are **frozen, not deleted** — they stay as historical comparison
until the Cloudflare beta passes its end-to-end gates, then get archived
through normal git history. The pre-monorepo `atoms-router/` and
`atoms-control-plane/` checkouts were already legacy before the pivot. The
workspace root `CLAUDE.md` still describes the Fly-era status and needs
updating once the pivot is formally recorded in an architecture decision.

## What's next (per the production plan)

1. Phase 0 closeout: record the pivot as an ADR in `internal-docs/`, freeze the old path formally.
2. Owned php-wasm build (hermetic, reproducible, trimmed extensions) replacing the Playground artifact.
3. Native `pdo_atoms` driver + host ABI as compiled imports — this is also what genuinely fixes the int64-read limitation.
4. Lifecycle completion: WebSockets (Hibernation API), alarms, `app()`/`dispatch()`/`broadcast()`.
5. Customer toolchain: `atoms build/dev/deploy/status/rollback/secrets` over Wrangler; GitHub Action on Cloudflare credentials.
