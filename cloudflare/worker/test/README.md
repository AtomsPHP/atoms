# Atoms-on-Cloudflare MVP Conformance Suite

This directory contains the conformance test suite for the Atoms MVP on Cloudflare Workers.

## Tests

`conformance.mjs` runs 12 checks against a live worker URL:

1. **healthz** — `/healthz` endpoint responds
2. **invoke + result envelope** — HTTP interface returns correct shape
3. **warm-residency** — in-memory state survives across turns in the same residency
4. **isolation between two IDs** — separate Atom instances maintain independent state
5. **migrations applied once** — schema migrations run on activation, not on each turn
6. **tx commit read-your-own-write** — transaction commits are durable
7. **tx rollback discards observed write** — rollbacks undo all writes in a transaction, and a transaction the turn *abandoned* (a forgotten `commit()`) rolls back into an `atom_exception` rather than destroying the residency
8. **uncaught exception** → `atom_exception` envelope, recovery works
9. **int64 matrix** — lossless int64 through args, SQL, results, and `lastInsertId`; `lastInsertId` survives an intervening read; a payload the JSON boundary cannot carry (over-deep args in, over-deep result out) is a typed error, never a poisoned residency
10. **reserved-table rejection** — `__atoms_*` tables are protected from customer code, including from SQL whose comments contain apostrophes
11. **turn serialization** — concurrent invokes on the same Atom serialize strictly
12. **eviction/wake** — in-memory state resets on eviction, durable state persists, `onActivation` re-runs

## Running Locally

### Start the worker

```bash
cd cloudflare/worker
npx wrangler dev
```

This starts the worker on `http://localhost:8787` by default.

### Run the suite

In another terminal:

```bash
cd cloudflare/worker

# Against local dev server
ATOMS_BASE_URL=http://localhost:8787 node test/conformance.mjs

# Skip specific checks (e.g., eviction which requires long wait)
ATOMS_BASE_URL=http://localhost:8787 ATOMS_SKIP=12 node test/conformance.mjs

# Custom eviction wait (useful for faster local testing)
ATOMS_BASE_URL=http://localhost:8787 ATOMS_EVICTION_WAIT_MS=5000 node test/conformance.mjs
```

## Running Remotely (Deployed)

### Deploy the worker

```bash
cd cloudflare/worker

# Bundle the fixture app, then publish
node scripts/build-bundle.mjs
npx wrangler deploy

# Set the bearer key. Generate one and keep your copy — a secret cannot be read
# back out of Cloudflare, only overwritten.
openssl rand -hex 32 | tee /dev/tty | npx wrangler secret put ATOMS_APP_KEY
```

This publishes to your Cloudflare Workers account. The worker will be available at a `workers.dev` URL.

`wrangler secret put` deploys a new version, and rollout is not instantaneous:
expect a few seconds during which requests may still be served by the previous
version (observed: an unauthenticated request already 401ing while a request
with a wrong key still got a 200 from the pre-secret version). Give a deploy or
a secret change a moment to settle before reading anything into an auth result.

### Debug endpoints

The suite's `debugInfo()` helper needs `GET /debug/:type/:id/info`, which is
gated on `ATOMS_DEBUG_ENDPOINTS=1`. It is a **var**, not a secret, and is
already set in `wrangler.jsonc` for this conformance deployment.

### Run the suite against deployed worker

```bash
# Get your worker URL from the deploy output, e.g.:
# Deployed to https://atoms-mvp-conformance.example.workers.dev/

ATOMS_BASE_URL=https://atoms-mvp-conformance.example.workers.dev \
ATOMS_APP_KEY=your-secret-key-here \
ATOMS_EVICTION_WAIT_MS=16000 \
node test/conformance.mjs
```

Deployed eviction is slower to arrive than local eviction; check 12 wants
`ATOMS_EVICTION_WAIT_MS=16000` remotely (the spec's "≥15s deployed").

## Configuration

Environment variables:

- `ATOMS_BASE_URL` (required) — URL of the running worker, e.g. `http://localhost:8787`
- `ATOMS_APP_KEY` (optional) — Bearer token for auth; if set, suite adds `Authorization: Bearer <key>` to all requests
- `ATOMS_EVICTION_WAIT_MS` (default 12500) — milliseconds to wait for DO eviction in check 12
- `ATOMS_SKIP` (optional) — comma-separated check numbers to skip, e.g. `10,11,12`

## Remote Results

`measure-remote.mjs` records the spec's remote-only additions — cold
activation, warm turn, and post-hibernation wake latency — into
`test/results/remote.json`, together with the deployed version id it measured:

```bash
ATOMS_BASE_URL=https://atoms-mvp-conformance.example.workers.dev \
ATOMS_APP_KEY=your-secret-key-here \
ATOMS_EVICTION_WAIT_MS=16000 \
node test/measure-remote.mjs
```

All figures are client-observed end-to-end latency and include the round trip
from wherever you run it to the Cloudflare edge — the guest clock does not
advance inside a turn on deployed workerd (spec appendix, deviation 3), so
there is no in-guest timing to report instead. The script fails loudly rather
than timing an error response, and warns if `constructions` did not increment,
which would mean the "wake" number is really a warm turn.

Measured 2026-08-05 against a deployed Worker from a US client:
cold activation ~740ms median (593–1033ms, n=5), warm turn ~59ms median
(51–96ms, n=20), post-hibernation wake ~604ms after 16s idle.

## Fixture App

The suite tests against the `fixtures/counter/` app, which includes:

- **Counter** Atom: demonstrates SQL reads/writes, in-memory state, lifecycle hooks, and array serialization. `slowIncrement()` is the deliberately slow method check 11 serializes; its delay is a bounded work budget rather than a clock wait, because the guest clock does not advance inside a turn on deployed workerd. `clockProbe()` is what measures that — it reports `hrtime` deltas across pure computation and across a host round trip, and returns zeros for both when deployed.
- **Vault** Atom: demonstrates int64 boundary cases, transactions, and PDO direct access

Both are defined in `fixtures/counter/manifest.json` and bundled into the worker at build time.

## Building the Bundle

The fixture app is bundled at build time:

```bash
cd cloudflare/worker
node scripts/build-bundle.mjs fixtures/counter src/bundle.generated.js
```

This generates `src/bundle.generated.js`, which the worker loads on startup.
