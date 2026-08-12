# Atoms-on-Cloudflare MVP Conformance Suite

This directory contains the conformance test suite for the Atoms MVP on Cloudflare Workers.

## Tests

`conformance.mjs` runs 25 checks against a live worker URL:

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
13. **`app()` round trip, int64-exact** — the boundary matrix through a signed callback, one request per call, valid signature/timestamp/nonce, no nonce reuse
14. **`app()` rejected inside a transaction** — `ATOMS-E082`, and the listener sees no request at all (the guest-side guard fires first)
15. **deadline overrun** — 15a uncaught: 504 `turn_deadline_exceeded` inside the configured budget and not far past it, residency stays healthy, and a later `app()` on the same Atom still works (the exhausted budget did not leak out of its turn); 15b caught: an ordinary 200, and the latched budget stops a second `app()` from reaching the network — then the *next* turn's `app()` succeeds, proving the latch is per turn, not per residency
16. **`dispatch()` awaited, signed, `kind=job`** — args keyed by promoted constructor property name, and the turn's HTTP response held until the delivery *completed*, not merely started (the listener stalls the job response by `ATOMS_TEST_JOB_DELAY_MS` and the check compares the two timestamps); then the same thing from `onActivation()`, on a fresh `Boot` atom, which is the one call site with no turn to belong to
17. **`dispatch()` transaction semantics** — dropped on rollback, delivered on commit, delivered even when dispatched outside a transaction ahead of an uncaught throw — and, in that last case, still awaited before the 500 goes out
18. **WebSocket connect + the `invocable_method()` denylist** — `onConnect` observed, the full query string delivered as `params`; bad upgrades (too many channels, a type with no handler) refused before any DO work; and all six runtime handlers (`onConnect`/`onMessage`/`onDisconnect`/`onTimer`/`onActivation`/`onDeactivation`) refused over `/invoke` on both an overriding and a non-overriding type, case variants included (`ONMESSAGE` reaches `onMessage()` in PHP), with responses asserted indistinguishable so they are not an oracle
19. **echo round trip** — `onMessage` + `Connection::send()`, text and binary frames
20. **`broadcast()`** — reaches every connection on the channel and only that channel, exact wire shape, empty channel is not an error, and a broadcast from inside a committed `db()->transaction()` is delivered (V3)
21. **THE BIG ONE — WebSocket survival across a real hibernation** — waits out a genuine eviction (asserts `constructions` grew, or fails rather than passing vacuously), same connection id and channels survive on the same socket, `onConnect` does not re-run, `onDisconnect` fires post-wake
22. **`send()` to a dead connection** — typed `ConnectionClosed`, scoped to that call only
23. **timers** — fire and are consumed (at-most-once), a schedule from inside `onTimer` chains, a schedule inside a rolled-back transaction never fires, `cancel()` works, a throwing `onTimer` is still consumed and the residency stays healthy, `__atoms_timers` is reserved, invalid names are `ATOMS-E085`
24. **THE HONEST ONE — a Durable Object alarm wakes an evicted atom** — waits out the same real eviction as 12/21, then confirms a due timer fires via the alarm alone, with no HTTP request involved
25. **a close wakes a hibernated Durable Object** — connect, exchange a frame, idle the full eviction wait with *no* traffic, close the socket and touch nothing further; the first (passive) debug read must show `constructions` grew, at least one WebSocket turn served in that residency, and no accept — then `onDisconnect` fired exactly once and `onConnect` did not re-run. Check 21 cannot cover this: it re-warms the residency before closing

Checks 13–17 need the suite's own in-process callback listener (see below) and
**skip** (never fail) when it is not configured — for example, when running
against `ATOMS_BASE_URL=<a deployed worker>` with no matching callback setup.
15 additionally skips when `ATOMS_TURN_DEADLINE_MS` is not set in the runner's
own environment. Set `ATOMS_REQUIRE_CALLBACK_CHECKS=1` to turn those skips into
failures: a skip is the right answer for a Worker that genuinely has no
callback channel, and the wrong answer for a run that started one — CI sets it,
so a broken `dev:callback` cannot quietly delete five checks. Checks 18–25 have
no such gate; they always run.

## Running Locally

### Start the worker

```bash
cd cloudflare/worker
npx wrangler dev
```

This starts the worker on `http://localhost:8787` by default, with no
callback channel configured — checks 13–17 will skip.

To also exercise the callback channel (checks 13–17), start the worker with
`npm run dev:callback` instead. It generates a fresh Ed25519 keypair for this
run only (never committed — see the script's own header), wires
`ATOMS_CALLBACK_URL`/`ATOMS_CALLBACK_SIGNING_KEY` into `wrangler dev` via
`--var`, and writes the public half plus the listener port to the gitignored
`test/.callback-key.json`, which `conformance.mjs` reads at startup to stand
up its own loopback listener and verify signatures against the matching
public key:

```bash
cd cloudflare/worker
npm run dev:callback
# or, forwarding a turn deadline for checks 15a/15b:
ATOMS_TURN_DEADLINE_MS=2000 npm run dev:callback
```

### Run the suite

In another terminal:

```bash
cd cloudflare/worker

# Against local dev server
ATOMS_BASE_URL=http://localhost:8787 node test/conformance.mjs

# Skip specific checks (e.g., eviction which requires long wait)
ATOMS_BASE_URL=http://localhost:8787 ATOMS_SKIP=12,21,24,25 node test/conformance.mjs

# Custom eviction wait (useful for faster local testing)
ATOMS_BASE_URL=http://localhost:8787 ATOMS_EVICTION_WAIT_MS=5000 node test/conformance.mjs

# Against a worker started with `npm run dev:callback` — enables 13-17
ATOMS_BASE_URL=http://localhost:8787 ATOMS_TURN_DEADLINE_MS=2000 \
  ATOMS_REQUIRE_CALLBACK_CHECKS=1 node test/conformance.mjs
```

`ATOMS_CALLBACK_PORT` overrides the loopback listener's port (default: the
port recorded in `test/.callback-key.json` by `dev-with-callback.mjs`) —
useful when running the listener alongside something else already bound to
that port.

**Client tooling for the WebSocket checks (18–22, 25): Node's built-in global
`WebSocket`, not the `ws` package.** `worker/package.json` declares
`GPL-2.0-or-later` because it describes the Worker *as assembled*, so a new
dependency there is a licensing question, not just a convenience. Node 22's
global `WebSocket` accepts `{headers: {Authorization: '...'}}` on the upgrade
(an undici extension, not standard `WebSocket`), which is what lets these
checks work identically against an auth-on deployed Worker and an auth-off
local one with zero added dependencies — but it means **Node 22 is the floor**
for running this suite. CI pins Node 22 (`.github/workflows/ci.yml`).

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

Deployed eviction is slower to arrive than local eviction; checks 12/21/24
want `ATOMS_EVICTION_WAIT_MS=16000` remotely (the spec's "≥15s deployed").

## Configuration

Environment variables:

- `ATOMS_BASE_URL` (required) — URL of the running worker, e.g. `http://localhost:8787`
- `ATOMS_APP_KEY` (optional) — Bearer token for auth; if set, suite adds `Authorization: Bearer <key>` to all requests
- `ATOMS_EVICTION_WAIT_MS` (default 12500) — milliseconds to wait for DO eviction in checks 12, 21, 24 and 25
- `ATOMS_CALLBACK_PORT` (optional) — port for the suite's own loopback callback listener; defaults to the port recorded in `test/.callback-key.json`
- `ATOMS_TURN_DEADLINE_MS` (optional) — must match the value the Worker was started with (`npm run dev:callback`'s own env var of the same name); required for check 15, which otherwise skips
- `ATOMS_REQUIRE_CALLBACK_CHECKS` (optional, `1`) — turn checks 13–17's skips into failures. Set it whenever the run *does* have a callback channel, so a broken harness cannot silently delete them; CI sets it
- `ATOMS_TEST_JOB_DELAY_MS` (default 400) — how long the suite's own listener holds a `kind=job` response open. A **test-harness** value, not a Worker setting: checks 16/17 compare when that response was sent against when the invoke response arrived, which is how an *awaited* delivery is told apart from a merely *started* one
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

- **Counter** Atom: demonstrates SQL reads/writes, in-memory state, lifecycle hooks, and array serialization. `slowIncrement()` is the deliberately slow method check 11 serializes; its delay is a bounded work budget rather than a clock wait, because the guest clock does not advance inside a turn on deployed workerd. `clockProbe()` is what measures that — it reports `hrtime` deltas across pure computation and across a host round trip, and returns zeros for both when deployed. `notify()` dispatches `App\Jobs\Notify` for checks 16-17.
- **Vault** Atom: demonstrates int64 boundary cases, transactions, and PDO direct access; `echoViaApp()`/`appInsideTransaction()`/`stallViaApp()`/`stallCaught()` drive checks 13-15.
- **Room** Atom (`"websocket": true` in the manifest): `onConnect`/`onMessage`/`onDisconnect` plus `broadcast()`, driving checks 18-22. A separate type from Counter/Vault on purpose, so it cannot perturb checks 3/11/12's exact `turnsThisResidency` assertions.
- **Scheduler** Atom: `arm()`/`cancelTimer()`/`scheduledMs()`/`timerLog()` over `$this->timers()`, driving checks 23-24.
- **`App\Jobs\Notify`**: the `AtomJob` `Counter::notify()` dispatches.

All are defined in `fixtures/counter/manifest.json` and bundled into the worker at build time.

## Building the Bundle

The fixture app is bundled at build time:

```bash
cd cloudflare/worker
node scripts/build-bundle.mjs fixtures/counter src/bundle.generated.js
```

This generates `src/bundle.generated.js`, which the worker loads on startup.
