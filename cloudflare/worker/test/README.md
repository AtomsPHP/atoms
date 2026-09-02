# Atoms-on-Cloudflare Conformance Suite

This directory contains the conformance test suite for the Atoms runtime on Cloudflare Workers.

## Tests

`conformance.mjs` runs 45 checks against a live worker URL:

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
13. **`app()` round trip, int64-exact** — the boundary matrix through a signed callback, one request per call, a 32-byte HMAC-SHA256 tag that verifies, valid timestamp/nonce, no nonce reuse
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
29. **sql result caps** — with `ATOMS_SQL_MAX_ROWS`/`ATOMS_SQL_MAX_RESULT_BYTES` set on both the Worker and the runner (matching, never defaulted, same pattern as check 15's turn deadline): one row under the row cap succeeds exactly, **exactly at the row cap also succeeds** (verified against `bridge.js`'s actual code: the cap check runs before push, at the top of each loop iteration, so the loop simply ends once `sqlMaxRows` rows have been pushed and is never re-entered to trip the check; the at-cap row is not rejected, only the first row past it is), one row over fails `sql_result_too_large` with `cap:'rows'` (asserted primarily from `BridgeSqlException::getDetail()['cap']`, with the message-text check kept as a secondary assertion), a result well under the row cap but over the byte cap fails with `cap:'bytes'` (same primary/secondary assertion, proving the caps are independent), **run mode (`PDO::exec()`, which discards rows) is exercised directly and must succeed even for a statement that would generate far more than the row cap in rows mode** (`bridge.js`'s `mode !== 'rows'` branch drains and discards without any cap check at all, verified directly rather than only documented), and the residency survives both failures
30. **pdo compatibility doc is current** — re-uses check 28's report, byte-compares a fresh render (`scripts/gen-pdo-matrix.mjs`, a pure function) against the committed `cloudflare/docs/pdo-compatibility.md`; a mismatch fails naming the first differing line and the regeneration command. If check 28 produced no report, this FAILS rather than skips
31. **tickets: locally issued ticket, headerless connect, claims win, ticket stripped** (no more mint route) — offline, the pinned `docs/ws-ticket-protocol.md` vectors reproduce byte-exactly and the reference secret derives the pinned ticket key; a completely headerless upgrade carrying a **locally issued** ticket connects the way a browser would, the ticket's `client_id` claim overrides the spoofed query param (server wins), the reserved `ticket` key is never delivered to `onConnect`, and a URL at exactly the documented default `ATOMS_WS_MAX_PARAMS` plus the ticket still opens (the ticket is outside the param budgets). Needs `ATOMS_SHARED_SECRET` to issue with, else skips
32. **tickets: the mint route is removed; /ws owns eligibility** (reworked when the mint route was removed — this check used to validate mint-time claims; that validation moved to the PHP unit suite, where `TicketIssuer` now lives) — `POST /tickets/Room/:id` **with** a credential is 404 `not_found`; a valid, correctly signed, locally issued ticket for a `websocket: false` type is 501 `not_supported` and for an unknown type is 404 `unknown_atom_type` — eligibility refused at the upgrade, on tickets that are otherwise perfectly good. Needs `ATOMS_SHARED_SECRET` to issue with, else skips
33. **edge refusals** — garbage and wrong-atom-scoped tickets are 401 `ticket_invalid`; a correctly signed ticket whose `exp` is already in the past is `ticket_expired`; a ticket **1ms past** its `exp` is `ticket_expired` (the sharpest statement of the changed expiry rule — under the old default skew this connected) and a ticket **5s from expiry** still connects (the boundary leg's non-vacuity guard); a `v1u.`-form string is `ticket_invalid` — all before any DO is addressed. The forged legs need `ATOMS_SHARED_SECRET`, else they skip
34. **reusable within TTL** — the same locally issued ticket opens a second connection both while the first socket is still open and after it has closed: the contract assertion that no single-use burn exists (the short TTL is the ticket's entire replay defense, and the DO holds no ticket state). Issues its own ticket, so it no longer needs a short server TTL
35. **mint route removed, issued ticket admits headerless upgrade** — a headerless `POST /tickets` is 401 `unauthenticated` (the credential gate precedes routing, so the route's absence is not observable without one); the same call with the bearer is 404 `not_found`; then the flagship leg, unchanged — a locally issued ticket admits a headerless browser-style upgrade with its claims merged
36. **bearer-required refusals** — a tampered signature and a `v1u.`-form string are both `ticket_invalid`; no credential at all is `unauthenticated`; and (no environment needed) a ticket issued with a 1.5s lifetime connects while valid, is waited out for real, and reconnecting gives `ticket_expired` — previously this leg needed `ATOMS_WS_TICKET_SKEW_MS` in the runner env and a Worker started with a short `ATOMS_WS_TICKET_TTL_MS`, both gone
37. **credential precedence** — a *tampered* ticket is 401 `ticket_invalid` headerless (the non-vacuity guard), then the same upgrade with a valid bearer connects: the bearer path strips the ticket unverified, never running the verifier at all. Issues its own ticket
38. **routing regression guard** — with bearer auth required, a headerless `POST /invoke` and `GET /debug` are still 401: the `/ws` ticket carve-out leaked into no other route
39. **bearer derivation** — (a) this runner reproduces the reference vector for all three purposes (`atoms/bearer/v1`, `atoms/ws-ticket/v1`, `atoms/callback/v1`) and the bearer is 44 characters of standard base64; (b) a live `php -r` doing `hash_hkdf('sha256', $ikm, 32, 'atoms/bearer/v1', '')` over the run's own secret produces exactly what the runner derives — the cross-language pin, because the monolith derives in PHP and the Worker in WebCrypto; (b2) a second cross-language leg: an inline `php -r` (no autoloader — the CI job has no composer install) runs the issuer's exact algorithm over the pinned ticket vector inputs and must produce the pinned ticket string, matching both the pinned vector and the runner's own implementation; (c) with bearer auth required, the Worker accepts the derived bearer and refuses a 44-character bearer derived from an unrelated secret
40. **rotation: bearers and tickets accepted under either secret, callbacks signed with the current key** (ticket legs flipped) — with `ATOMS_SHARED_SECRET_PREVIOUS` configured on the Worker and the runner: both `bearer(current)` and `bearer(previous)` are accepted on `/invoke` while a bearer from an unrelated secret is 401; a ticket signed under the **previous** secret now **connects** (it used to be refused — local issuance is what made the old refusal wrong), one signed under the **current** key connects too, and one signed under an **unrelated** secret is still `ticket_invalid`, so neither acceptance is vacuous; and the callback the listener receives verifies under the current callback key and not under the previous one (a verifier accepts both, a sender emits only the current value)
41. **misconfigured Worker** — booted with no shared secret, `GET /healthz` still answers 200 `{ok:true}` and `/invoke`, `/tickets`, `/debug` and `/ws` all answer HTTP 500 with the wire code `misconfigured` — including `/tickets`, which pins that the configuration gate precedes routing even for a route that no longer exists
42. **config deny list** — with the Worker's `ATOMS_CONFIG_ENV_KEYS` naming `ATOMS_SHARED_SECRET` and `ATOMS_SHARED_SECRET_PREVIOUS`, a guest `$this->config()` of either name resolves `null`; an allowlisted control key on the same list resolves, which is what makes the two nulls meaningful rather than vacuous
43. **structured WebSocket frames** — `Connection::sendJson()` puts a payload on the wire **bare**, with no `kind:"broadcast"` envelope, slashes unescaped and an integer past 2^53-1 intact; `Message::json()` round-trips an object and preserves a nested list; a nested empty map still encodes as `[]` (only the top level is forced to an object, deliberately); and a top-level list and malformed JSON both reach the Atom as one `\JsonException`. Every frame is compared as a raw string — `JSON.parse` would round the int64 and hide the envelope
44. **malformed rotation overlap** — booted with a **valid** current secret but a malformed `ATOMS_SHARED_SECRET_PREVIOUS`, the Worker is exactly as loudly broken as check 41's: `/healthz` answers 200 `{ok:true}` and `/invoke`, `/tickets`, `/debug` and `/ws` all answer HTTP 500 `misconfigured` — and each refusal message names `ATOMS_SHARED_SECRET_PREVIOUS`, which is what proves the gate tripped on the overlap and not on the current secret. Pins the spec's "set but malformed → misconfigured" requirement that checks 40 (valid overlap) and 41 (no current secret) cannot see
45. **vendor tree autoload** — the manifest's `vendor.autoload` classmap loads a vendor class the line-scanning bundle autoloader cannot index (`Acme\Greeter\Greeter` is declared indented inside a conditional, on purpose), and a Composer-style function file is already loaded before the class is first touched, proving "files" entries are required eagerly at activation

### Configurations

The suite runs against a Worker in one of the configurations below; the runner is
told which one through `ATOMS_BEARER_AUTH` (the two misconfigured runs are
named by their `ATOMS_EXPECT_MISCONFIGURED*` flag instead):

| configuration | Worker env | what it exercises |
|---|---|---|
| bearer required (default) | `ATOMS_SHARED_SECRET` set, `ATOMS_BEARER_AUTH` unset or `required` | everything, including 35–38 and check 39's live leg |
| bearer disabled | `ATOMS_SHARED_SECRET` set, `ATOMS_BEARER_AUTH=disabled` | everything except the bearer-gated checks; tickets are still signed and callbacks are still signed |
| misconfigured | no `ATOMS_SHARED_SECRET` | check 41 only (`ATOMS_ONLY=41 ATOMS_EXPECT_MISCONFIGURED=1`) |
| malformed previous | valid `ATOMS_SHARED_SECRET`, malformed `ATOMS_SHARED_SECRET_PREVIOUS` | check 44 only (`ATOMS_ONLY=44 ATOMS_EXPECT_MISCONFIGURED_PREVIOUS=1`) |

Checks 31–34 run in either configured posture. 35–38 need bearer auth
required and otherwise skip. Checks 31, 32, 34, 35, 36 and 37 also need
`ATOMS_SHARED_SECRET` in the runner's own environment, since they issue
tickets locally now instead of minting them through the Worker.

### Skips and the `ATOMS_REQUIRE_*` flags

A skip is the right answer for a Worker that genuinely lacks a prerequisite
(no callback channel, no rotation overlap) and the wrong answer for a run that
set one up. Each gate therefore has a matching flag that turns its skip into a
failure, so a broken harness cannot quietly delete checks; CI sets every flag
the posture can satisfy.

| checks | skip when | flag |
|---|---|---|
| 13–17 | no callback listener (needs `ATOMS_SHARED_SECRET` and a port); 15 additionally needs `ATOMS_TURN_DEADLINE_MS` | `ATOMS_REQUIRE_CALLBACK_CHECKS=1` |
| 29 | `ATOMS_SQL_MAX_ROWS`/`ATOMS_SQL_MAX_RESULT_BYTES` not in the runner env | `ATOMS_REQUIRE_SQL_CAP_CHECKS=1` |
| 31, 32, 34 | no `ATOMS_SHARED_SECRET` (issuing a ticket needs the root) | `ATOMS_REQUIRE_TICKET_CHECKS=1` |
| 33 (scope/expiry/unsigned-form legs) | no `ATOMS_SHARED_SECRET` | `ATOMS_REQUIRE_TICKET_CHECKS=1` |
| 35–37 | no `ATOMS_SHARED_SECRET`; bearer auth not required | `ATOMS_REQUIRE_TICKET_CHECKS=1` |
| 38 | bearer auth not required | `ATOMS_REQUIRE_TICKET_CHECKS=1` |
| 39 (cross-language legs) | no `php` on PATH | `ATOMS_REQUIRE_BEARER_VECTOR=1` |
| 40 | no `ATOMS_SHARED_SECRET_PREVIOUS` | `ATOMS_REQUIRE_ROTATION_CHECKS=1` |
| 41 | `ATOMS_EXPECT_MISCONFIGURED` unset (the flag is the gate) | — |
| 44 | `ATOMS_EXPECT_MISCONFIGURED_PREVIOUS` unset (the flag is the gate) | — |
| 42 | the Worker's `ATOMS_CONFIG_ENV_KEYS` does not make the control key readable | `ATOMS_REQUIRE_DENY_CHECKS=1` |

Checks 18–28, 30 and 45 have no gate; they always run. Check 30 never skips — a
stale doc is not excused by a missing run.

## Tests that are not conformance checks

Two files here run without a Worker, a network, or `npm ci`:

| File | `npm run` | What it holds |
|---|---|---|
| `runtime-package.mjs` | `test:package` | the public runtime scaffold's shape |
| `atom-do-instance.mjs` | `test:instance` | a discarded PHP instance cannot report onto a live one |

`atom-do-instance.mjs` exists because the conformance suite cannot express its
subject. `php.run()` is never awaited and ends only when the guest exits, so a
run belonging to a discarded instance can settle after a fresh instance has
booted into the same residency — and the suite, which drives a real Worker over
HTTP, has no way to hold one instance's run promise open across another's boot.
The test drives the real `watchRun()`/`settleRun()` with promises whose settle
moment it chooses, stubbing `cloudflare:workers` and `php-host.js` (a `.wasm`
import) through a loader hook. Needs Node >= 22.15 for `module.registerHooks`.

## Credentials

The boundary has one operator-facing root, **`ATOMS_SHARED_SECRET`**: 32
random bytes, base64, configured identically on the monolith and the Worker.
Every key on the boundary is HKDF-SHA256 derived from it — the bearer
(`atoms/bearer/v1`), the WebSocket ticket key (`atoms/ws-ticket/v1`) and the
callback key (`atoms/callback/v1`). See `docs/shared-secret.md` for the
normative contract and the reference vector check 39 pins.

**The root never travels.** What goes on the wire is the derived bearer, not
the secret, so a leaked request header compromises invocation only. Two
consequences for anyone running this suite:

- `atoms token` prints the derived bearer. Every curl example below uses
  `Authorization: Bearer $(atoms token)` — never the secret.
- A run against a deployed Worker does not need the root: set
  `ATOMS_BEARER_TOKEN` to the derived bearer instead, and the checks that need
  the root (forging tickets, verifying callbacks, rotation) skip.

Generate a secret with `openssl rand -base64 32`. Nothing commits one: local
runs generate a fresh secret per run into the gitignored
`test/.dev-secret.json`, and CI generates one per job.

## Running Locally

### Start the worker

```bash
cd cloudflare/worker
npx wrangler dev
```

This starts the worker on `http://localhost:8787` with whatever
`.dev.vars` provides. With no `ATOMS_SHARED_SECRET` there, every route except
`/healthz` answers `misconfigured` — the posture check 41 asserts.

For an ordinary run, start it with `npm run dev:callback` instead. It
generates a shared secret for this run only (never committed — see the
script's own header), wires it and a loopback `ATOMS_CALLBACK_URL` into
`wrangler dev` via `--var`, and writes the secret plus the listener port to
the gitignored `test/.dev-secret.json`, which `conformance.mjs` reads at
startup to derive its bearer, stand up its own loopback listener, and verify
callback signatures:

```bash
cd cloudflare/worker
npm run dev:callback
# or, forwarding a turn deadline for checks 15a/15b:
ATOMS_TURN_DEADLINE_MS=2000 npm run dev:callback
# or, also forwarding small result-set caps for check 29:
ATOMS_TURN_DEADLINE_MS=2000 ATOMS_SQL_MAX_ROWS=500 ATOMS_SQL_MAX_RESULT_BYTES=65536 npm run dev:callback
# the bearer-disabled posture (an authenticating proxy stands in front):
ATOMS_BEARER_AUTH=disabled npm run dev:callback
```

### Run the suite

In another terminal:

```bash
cd cloudflare/worker

# Against a local dev server started by `npm run dev:callback`: the secret and
# the callback port both come from test/.dev-secret.json.
ATOMS_BASE_URL=http://localhost:8787 node test/conformance.mjs

# Skip specific checks (e.g., eviction which requires long wait)
ATOMS_BASE_URL=http://localhost:8787 ATOMS_SKIP=12,21,24,25 node test/conformance.mjs

# Custom eviction wait (useful for faster local testing)
ATOMS_BASE_URL=http://localhost:8787 ATOMS_EVICTION_WAIT_MS=5000 node test/conformance.mjs

# Enabling the callback checks' anti-silent-deletion flag
ATOMS_BASE_URL=http://localhost:8787 ATOMS_TURN_DEADLINE_MS=2000 \
  ATOMS_REQUIRE_CALLBACK_CHECKS=1 node test/conformance.mjs

# Also forwarding matching result-set caps — enables 29
ATOMS_BASE_URL=http://localhost:8787 ATOMS_TURN_DEADLINE_MS=2000 \
  ATOMS_SQL_MAX_ROWS=500 ATOMS_SQL_MAX_RESULT_BYTES=65536 \
  ATOMS_REQUIRE_CALLBACK_CHECKS=1 ATOMS_REQUIRE_SQL_CAP_CHECKS=1 node test/conformance.mjs

# Against a Worker running the bearer-disabled posture
ATOMS_BASE_URL=http://localhost:8787 ATOMS_BEARER_AUTH=disabled node test/conformance.mjs
```

`ATOMS_CALLBACK_PORT` overrides the loopback listener's port (default: the
port recorded in `test/.dev-secret.json` by `dev-with-callback.mjs`) — useful
when running the listener alongside something else already bound to that port.
`ATOMS_SHARED_SECRET` in the runner's own environment overrides the recorded
secret, which is how CI drives a run whose secret it minted itself.

**Client tooling for the WebSocket checks: Node's built-in global `WebSocket`,
not the `ws` package.** Node 22's
global `WebSocket` accepts `{headers: {Authorization: '...'}}` on the upgrade
(an undici extension, not standard `WebSocket`), which is what lets these
checks work identically against a deployed Worker and a local one with zero
added dependencies — but it means **Node 22 is the floor** for running this
suite. CI pins Node 22 (`.github/workflows/ci.yml`).

## Running Remotely (Deployed)

### Deploy the worker

```bash
cd cloudflare/worker

# Bundle the fixture app, then publish
node scripts/build-bundle.mjs
npx wrangler deploy

# Set the shared secret. Generate one and keep your copy — a secret cannot be
# read back out of Cloudflare, only overwritten.
openssl rand -base64 32 | tee /dev/tty | npx wrangler secret put ATOMS_SHARED_SECRET
```

This publishes to your Cloudflare Workers account. The worker will be available at a `workers.dev` URL.

`wrangler secret put` deploys a new version, and rollout is not instantaneous:
expect a few seconds during which requests may still be served by the previous
version. Give a deploy or a secret change a moment to settle before reading
anything into an auth result.

A smoke test uses the derived bearer, never the secret:

```bash
curl -sS -X POST "$WORKER_URL/invoke/Counter/demo/increment" \
  -H "Authorization: Bearer $(atoms token)" \
  -H 'Content-Type: application/json' \
  -d '{"args":[1]}'
```

### Debug endpoints

The suite's `debugInfo()` helper needs `GET /debug/:type/:id/info`, which is
gated on `ATOMS_DEBUG_ENDPOINTS=1`. It is a **var**, not a secret, and is
already set in `wrangler.jsonc` for this conformance deployment.

### Run the suite against deployed worker

```bash
# Get your worker URL from the deploy output, e.g.:
# Deployed to https://atoms-conformance.example.workers.dev/

ATOMS_BASE_URL=https://atoms-conformance.example.workers.dev \
ATOMS_BEARER_TOKEN="$(atoms token)" \
ATOMS_EVICTION_WAIT_MS=16000 \
node test/conformance.mjs
```

`ATOMS_BEARER_TOKEN` carries invoke capability and nothing else, which is why
it is the right thing to hand a remote run: checks 13–17, 31, 32, 33's forged
legs, 34, 35, 36, 37, 40 and 42 skip (they all need the root to issue a
ticket, forge a callback signature, or read the rotation overlap), and
everything else runs. Pass `ATOMS_SHARED_SECRET` instead when the run is
meant to cover them too.

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
- `ATOMS_SHARED_SECRET` (optional) — the root, base64, exactly 32 bytes once decoded. The full-capability form: the runner derives its bearer, issues tickets locally (the Worker no longer mints them), and verifies callbacks. Defaults to the value in `test/.dev-secret.json`
- `ATOMS_BEARER_TOKEN` (optional) — the derived bearer (what `atoms token` prints), for a run that must not carry the root. Used verbatim
- `ATOMS_BEARER_AUTH` (optional, `required` — the default — or `disabled`) — the posture the Worker under test runs. `required` is what makes the suite present a bearer; anything unrecognized is treated as `required`
- `ATOMS_SHARED_SECRET_PREVIOUS` (optional) — the rotation overlap secret the Worker was started with; required for check 40, which otherwise skips
- `ATOMS_EVICTION_WAIT_MS` (default 12500) — milliseconds to wait for DO eviction in checks 12, 21, 24 and 25
- `ATOMS_CALLBACK_PORT` (optional) — port for the suite's own loopback callback listener; defaults to the port recorded in `test/.dev-secret.json`
- `ATOMS_TURN_DEADLINE_MS` (optional) — must match the value the Worker was started with (`npm run dev:callback`'s own env var of the same name); required for check 15, which otherwise skips
- `ATOMS_REQUIRE_CALLBACK_CHECKS` (optional, `1`) — turn checks 13–17's skips into failures. Set it whenever the run *does* have a callback channel, so a broken harness cannot silently delete them; CI sets it
- `ATOMS_TEST_JOB_DELAY_MS` (default 400) — how long the suite's own listener holds a `kind=job` response open. A **test-harness** value, not a Worker setting: checks 16/17 compare when that response was sent against when the invoke response arrived, which is how an *awaited* delivery is told apart from a merely *started* one
- `ATOMS_SQL_MAX_ROWS` / `ATOMS_SQL_MAX_RESULT_BYTES` (optional) — must match the values the Worker was started with (`npm run dev:callback`'s own env vars of the same name, forwarded to `wrangler --var`); required for check 29, which otherwise skips
- `ATOMS_REQUIRE_SQL_CAP_CHECKS` (optional, `1`) — turn check 29's skip into a failure, same device as `ATOMS_REQUIRE_CALLBACK_CHECKS`, a separate flag
- `ATOMS_REQUIRE_TICKET_CHECKS` (optional, `1`) — turn any connection-ticket skip into a failure; set it on the bearer-required run. `ATOMS_WS_TICKET_SKEW_MS` is gone from the runner entirely (expiry is absolute, `now >= exp`, no skew), and the ticket TTL is issuer-side configuration now (`docs/ws-ticket-protocol.md`) — the Worker no longer reads either `ATOMS_WS_TICKET_SKEW_MS` or `ATOMS_WS_TICKET_TTL_MS`
- `ATOMS_REQUIRE_BEARER_VECTOR` (optional, `1`) — turn check 39's cross-language skip (no `php` on PATH) into a failure; CI sets it
- `ATOMS_REQUIRE_ROTATION_CHECKS` (optional, `1`) — turn check 40's skip into a failure; set it on the run whose Worker carries `ATOMS_SHARED_SECRET_PREVIOUS`
- `ATOMS_REQUIRE_DENY_CHECKS` (optional, `1`) — turn check 42's skip into a failure; set it on the run whose Worker lists the secret names in `ATOMS_CONFIG_ENV_KEYS`
- `ATOMS_EXPECT_MISCONFIGURED` (optional, `1`) — the Worker under test was booted with no shared secret: run check 41 and expect `misconfigured` everywhere but `/healthz`
- `ATOMS_EXPECT_MISCONFIGURED_PREVIOUS` (optional, `1`) — the Worker under test was booted with a valid current secret and a malformed `ATOMS_SHARED_SECRET_PREVIOUS`: run check 44 and expect `misconfigured` everywhere but `/healthz`, each refusal naming the previous variable
- `ATOMS_SKIP` (optional) — comma-separated check numbers to skip, e.g. `10,11,12`
- `ATOMS_ONLY` (optional) — comma-separated allowlist: run only these check numbers

## Remote Results

`measure-remote.mjs` records the spec's remote-only additions — cold
activation, warm turn, and post-hibernation wake latency — into
`test/results/remote.json`, together with the deployed version id it measured:

```bash
ATOMS_BASE_URL=https://atoms-conformance.example.workers.dev \
ATOMS_BEARER_TOKEN="$(atoms token)" \
ATOMS_EVICTION_WAIT_MS=16000 \
node test/measure-remote.mjs
```

It accepts `ATOMS_SHARED_SECRET` too and derives the bearer itself; the token
form is preferred for the same reason it is above.

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

- **Counter** Atom: demonstrates SQL reads/writes, in-memory state, lifecycle hooks, and array serialization. `slowIncrement()` is the deliberately slow method check 11 serializes; its delay is a bounded work budget rather than a clock wait, because the guest clock does not advance inside a turn on deployed workerd. `clockProbe()` is what measures that — it reports `hrtime` deltas across pure computation and across a host round trip, and returns zeros for both when deployed. `notify()` dispatches `App\Jobs\Notify` for checks 16-17. `configProbe(keys)` reports what `$this->config()` resolves for each key, driving check 42's deny-list assertion.
- **Vault** Atom: demonstrates int64 boundary cases, transactions, and PDO direct access; `echoViaApp()`/`appInsideTransaction()`/`stallViaApp()`/`stallCaught()` drive checks 13-15.
- **Room** Atom (`"websocket": true` in the manifest): `onConnect`/`onMessage`/`onDisconnect` plus `broadcast()`, driving checks 18-22. A separate type from Counter/Vault on purpose, so it cannot perturb checks 3/11/12's exact `turnsThisResidency` assertions.
- **Scheduler** Atom: `arm()`/`cancelTimer()`/`scheduledMs()`/`timerLog()` over `$this->timers()`, driving checks 23-24.
- **Probe** Atom: the PDO surface work. `surfaceAudit()` drives check 26; `comparatorSanity()`/`differentialGroups()`/`differential(group)` drive checks 27-28; `capProbe(cap, rows, padBytes)` and `capProbeRunMode(rows)` (both recursive CTEs, no writes) drive check 29. A separate type for the same reason Room/Scheduler are.
- **Vendor** Atom: `viaVendor()` drives check 45 over the bundled vendor tree under `app/vendor/` and the manifest's `vendor.autoload` key. A separate type for the same reason Room/Scheduler/Probe are.
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
