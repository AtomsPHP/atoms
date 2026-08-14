# Atoms-on-Cloudflare MVP Conformance Suite

This directory contains the conformance test suite for the Atoms MVP on Cloudflare Workers.

## Tests

`conformance.mjs` runs 30 checks against a live worker URL:

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
16. **`dispatch()` awaited, signed, `kind=job`** — args keyed by promoted constructor property name, and the turn's HTTP response held until the delivery *completed*, not merely started (the listener stalls the job response by `ATOMS_TEST_JOB_DELAY_MS` and the check compares the two timestamps); then the same thing from `onActivation()`, on a fresh `Boot` atom, which is the one call site with no turn to belong to — whose `onActivation()` also calls `app()`, so the check additionally asserts the listener saw one signed `kind=methods` request (`echoBig` on `Boot`), which only succeeds if the activation budget is stamped fresh past wasm boot and migrations rather than charged for them
17. **`dispatch()` transaction semantics** — dropped on rollback, delivered on commit, delivered even when dispatched outside a transaction ahead of an uncaught throw — and, in that last case, still awaited before the 500 goes out
18. **WebSocket connect + the `invocable_method()` denylist** — `onConnect` observed, the full query string delivered as `params`; bad upgrades (too many channels, a type with no handler) refused before any DO work; and all six runtime handlers (`onConnect`/`onMessage`/`onDisconnect`/`onTimer`/`onActivation`/`onDeactivation`) refused over `/invoke` on both an overriding and a non-overriding type, case variants included (`ONMESSAGE` reaches `onMessage()` in PHP), with responses asserted indistinguishable so they are not an oracle
19. **echo round trip** — `onMessage` + `Connection::send()`, text and binary frames
20. **`broadcast()`** — reaches every connection on the channel and only that channel, exact wire shape, empty channel is not an error, and a broadcast from inside a committed `db()->transaction()` is delivered (V3)
21. **THE BIG ONE — WebSocket survival across a real hibernation** — waits out a genuine eviction (asserts `constructions` grew, or fails rather than passing vacuously), same connection id and channels survive on the same socket, `onConnect` does not re-run, `onDisconnect` fires post-wake
22. **`send()` to a dead connection** — typed `ConnectionClosed`, scoped to that call only
23. **timers** — fire and are consumed (at-most-once), a schedule from inside `onTimer` chains, a schedule inside a rolled-back transaction never fires, `cancel()` works, a throwing `onTimer` is still consumed and the residency stays healthy, `__atoms_timers` is reserved, invalid names are `ATOMS-E085`
24. **THE HONEST ONE — a Durable Object alarm wakes an evicted atom** — waits out the same real eviction as 12/21, then confirms a due timer fires via the alarm alone, with no HTTP request involved
25. **a close wakes a hibernated Durable Object** — connect, exchange a frame, idle the full eviction wait with *no* traffic, close the socket and touch nothing further; the first (passive) debug read must show `constructions` grew, at least one WebSocket turn served in that residency, and no accept — then `onDisconnect` fired exactly once and `onConnect` did not re-run. Because `GET /debug` constructs the DO (without activating PHP), the check also asserts the woken residency's `resident_ms` is greater than the elapsed time since its own first poll, proving that poll did not create the residency the close did. Check 21 cannot cover this: it re-warms the residency before closing
26. **pdo surface tripwire** — a reflection-driven audit (never a hardcoded member list) asserts every public member of the RUNTIME `\PDO`/`\PDOStatement` is genuinely declared on `Atoms\Cf\AtomsPDO`/`AtomsStatement`, the pinned `FETCH_*`/`ATTR_*`/`PARAM_*`/... constants match the runtime by name-set and value, every pinned `FETCH_*` value is proven refused or shaped correctly by execution, anti-vacuous floors on how much was actually checked, and the allowlist (currently one entry) is exactly the committed id set with every entry's own runtime assertion passed
27. **pdo comparator integrity** — a fresh native in-guest `new \PDO('sqlite::memory:')` passes five structural gates (exact class, a real `ATTR_CLIENT_VERSION`, `FETCH_NAMED` duplicate-column grouping, `getColumnMeta()`, a genuine `PDORow` from `FETCH_LAZY`) that `AtomsPDO` cannot produce even in principle — so an impostor or misconfigured comparator cannot pass. Never skips
28. **pdo differential matrix** — ~160 cases, the SAME closure run against `AtomsPDO` and the check-27 comparator, classified and checked against the committed pin file `pdo-expected.json` in both directions (every observed difference must be pinned with exactly that class; every pin must be observed with exactly that class — the direction that catches a comparator that quietly became `AtomsPDO` itself), plus anti-vacuous floors and a hard zero on harness-breakage cases
29. **sql result caps** — with `ATOMS_SQL_MAX_ROWS`/`ATOMS_SQL_MAX_RESULT_BYTES` set on both the Worker and the runner (matching, never defaulted, same pattern as check 15's turn deadline): one row under the row cap succeeds exactly, **exactly at the row cap also succeeds** (M1 review F-15 — verified against `bridge.js`'s actual code: the cap check runs before push, at the top of each loop iteration, so the loop simply ends once `sqlMaxRows` rows have been pushed and is never re-entered to trip the check; the at-cap row is not rejected, only the first row past it is), one row over fails `sql_result_too_large` with `cap:'rows'` (asserted primarily from `BridgeSqlException::getDetail()['cap']`, M1 review F-14 — this used to be readable only by parsing the message text, because the detail object was silently dropped in `SqlBridge::failure()` before ever reaching PHP; the message-text check is kept as a secondary assertion), a result well under the row cap but over the byte cap fails with `cap:'bytes'` (same primary/secondary assertion, proving the caps are independent), **run mode (`PDO::exec()`, which discards rows) is exercised directly and must succeed even for a statement that would generate far more than the row cap in rows mode** (M1 review F-15 — `bridge.js`'s `mode !== 'rows'` branch drains and discards without any cap check at all, verified directly rather than only documented), and the residency survives both failures
30. **pdo compatibility doc is current** — re-uses check 28's report, byte-compares a fresh render (`scripts/gen-pdo-matrix.mjs`, a pure function) against the committed `cloudflare/docs/pdo-compatibility.md`; a mismatch fails naming the first differing line and the regeneration command. If check 28 produced no report, this FAILS rather than skips

Checks 13–17 need the suite's own in-process callback listener (see below) and
**skip** (never fail) when it is not configured — for example, when running
against `ATOMS_BASE_URL=<a deployed worker>` with no matching callback setup.
15 additionally skips when `ATOMS_TURN_DEADLINE_MS` is not set in the runner's
own environment. Set `ATOMS_REQUIRE_CALLBACK_CHECKS=1` to turn those skips into
failures: a skip is the right answer for a Worker that genuinely has no
callback channel, and the wrong answer for a run that started one — CI sets it,
so a broken `dev:callback` cannot quietly delete five checks. Checks 18–25 have
no such gate; they always run. Check 29 has its own, independent gate: it
skips when `ATOMS_SQL_MAX_ROWS`/`ATOMS_SQL_MAX_RESULT_BYTES` are not set in the
runner's own environment, and `ATOMS_REQUIRE_SQL_CAP_CHECKS=1` turns that skip
into a failure, same device, separate flag. Check 30 never skips — a stale doc
is not excused by a missing run.

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
# or, also forwarding small result-set caps for check 29:
ATOMS_TURN_DEADLINE_MS=2000 ATOMS_SQL_MAX_ROWS=500 ATOMS_SQL_MAX_RESULT_BYTES=65536 npm run dev:callback
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

# Also forwarding matching result-set caps — enables 29
ATOMS_BASE_URL=http://localhost:8787 ATOMS_TURN_DEADLINE_MS=2000 \
  ATOMS_SQL_MAX_ROWS=500 ATOMS_SQL_MAX_RESULT_BYTES=65536 \
  ATOMS_REQUIRE_CALLBACK_CHECKS=1 ATOMS_REQUIRE_SQL_CAP_CHECKS=1 node test/conformance.mjs
```

`ATOMS_CALLBACK_PORT` overrides the loopback listener's port (default: the
port recorded in `test/.callback-key.json` by `dev-with-callback.mjs`) —
useful when running the listener alongside something else already bound to
that port.

**Client tooling for the WebSocket checks: Node's built-in global `WebSocket`,
not the `ws` package.** Node 22's
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

Check 29 (the result-set size guard) follows the same honesty contract as
check 15: nothing in `Probe::capProbe()` depends on localhost, so it runs
unchanged against a deployed Worker — but only if the deployed Worker's
`ATOMS_SQL_MAX_ROWS`/`ATOMS_SQL_MAX_RESULT_BYTES` are known and exported to
the runner as the same values (`wrangler secret`/`vars` do not echo back, so
the operator has to already know what was deployed with). If the deployed
Worker carries different values, or the platform defaults, export those
instead; if the caps are simply unknown, leave both unset and check 29 skips
rather than asserting a wrong cap fired.

## Configuration

Environment variables:

- `ATOMS_BASE_URL` (required) — URL of the running worker, e.g. `http://localhost:8787`
- `ATOMS_APP_KEY` (optional) — Bearer token for auth; if set, suite adds `Authorization: Bearer <key>` to all requests
- `ATOMS_EVICTION_WAIT_MS` (default 12500) — milliseconds to wait for DO eviction in checks 12, 21, 24 and 25
- `ATOMS_CALLBACK_PORT` (optional) — port for the suite's own loopback callback listener; defaults to the port recorded in `test/.callback-key.json`
- `ATOMS_TURN_DEADLINE_MS` (optional) — must match the value the Worker was started with (`npm run dev:callback`'s own env var of the same name); required for check 15, which otherwise skips
- `ATOMS_REQUIRE_CALLBACK_CHECKS` (optional, `1`) — turn checks 13–17's skips into failures. Set it whenever the run *does* have a callback channel, so a broken harness cannot silently delete them; CI sets it
- `ATOMS_TEST_JOB_DELAY_MS` (default 400) — how long the suite's own listener holds a `kind=job` response open. A **test-harness** value, not a Worker setting: checks 16/17 compare when that response was sent against when the invoke response arrived, which is how an *awaited* delivery is told apart from a merely *started* one
- `ATOMS_SQL_MAX_ROWS` / `ATOMS_SQL_MAX_RESULT_BYTES` (optional) — must match the values the Worker was started with (`npm run dev:callback`'s own env vars of the same name, forwarded to `wrangler --var`); required for check 29, which otherwise skips
- `ATOMS_REQUIRE_SQL_CAP_CHECKS` (optional, `1`) — turn check 29's skip into a failure, same device as `ATOMS_REQUIRE_CALLBACK_CHECKS`, a separate flag. Set it whenever the run *does* have both cap vars configured; CI sets it
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
- **Probe** Atom: the M1 PDO surface work. `surfaceAudit()` drives check 26; `comparatorSanity()`/`differentialGroups()`/`differential(group)` drive checks 27-28; `capProbe(cap, rows, padBytes)` and `capProbeRunMode(rows)` (both recursive CTEs, no writes) drive check 29. A separate type for the same reason Room/Scheduler are.
- **`App\Jobs\Notify`**: the `AtomJob` `Counter::notify()` dispatches.

All are defined in `fixtures/counter/manifest.json` and bundled into the worker at build time.

## Building the Bundle

The fixture app is bundled at build time:

```bash
cd cloudflare/worker
node scripts/build-bundle.mjs fixtures/counter src/bundle.generated.js
```

This generates `src/bundle.generated.js`, which the worker loads on startup.

## Regenerating the PDO Compatibility Doc

`cloudflare/docs/pdo-compatibility.md` is generated, not hand-edited. Check 30
fails the run if it and a fresh render of check 28's differential report
disagree. After a fill or a pin change, regenerate it against a run that
covers everything (the local `wrangler dev` run above is enough — the report
does not depend on which base URL produced it):

```bash
cd cloudflare/worker
node scripts/gen-pdo-matrix.mjs > ../docs/pdo-compatibility.md
```

`scripts/gen-pdo-matrix.mjs` also exports `renderMatrixDoc(report, pins)` as a
pure function (no filesystem, no clock) — this is what check 30 imports to
byte-compare in-process, rather than shelling out to the CLI above.
