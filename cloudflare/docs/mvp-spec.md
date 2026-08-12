# Atoms-on-Cloudflare MVP specification

**Status:** binding for the MVP implementation under `cloudflare/`.
**Parent:** the pre-MVP Durable-Object spikes, which established the direction
and the proven mechanism this implementation ports. Those are internal and are
not in this repository.

## Scope

In scope: one Worker hosting a generic `AtomDurableObject`; a persistent
parked PHP loop per active DO; the real `atoms/core` PHP ABI running unmodified
inside the guest; `db()` (query/execute/transaction + a documented-leaky
`pdo()`), `config()`, migrations, lossless int64; a fixture app; a local
conformance suite against `wrangler dev`; a real deploy + remote smoke. As of
M2 (2026-08-12), also in scope and implemented: `app()`/`dispatch()` over a
signed callback channel to the monolith, WebSockets (`onConnect`/`onMessage`/
`onDisconnect`) over the Hibernation API, `broadcast()`, and named timers
(`$this->timers()`) backed by a single multiplexed Durable Object alarm.

Out of scope (MVP): owned php-wasm build, native `pdo_atoms`, most of the
`atoms` CLI/action (`deploy`/`dev`/`status`/`rollback`/`secrets:*` have
shipped since M3 — see `docs/cloudflare-toolchain.md`; the rest is still
outstanding). `AtomsPDO`/`AtomsStatement` still throw a typed
`AtomsNotSupported` PDO-style exception for the unsupported corners of the PDO
surface documented in `worker/php/README.md` §Documented leaks and limits —
that is a permanent MVP restriction of the PDO shim, not a stub awaiting a
later milestone. These remain explicit stubs/restrictions, never silent
no-ops.

## Runtime artifact

The pinned artifact is `php_8_3.asyncify.{js,wasm}` (WordPress Playground PHP
8.3 build, 64-bit ints, Asyncify). It is **not in the repository**: since the
2026-08-08 licensing work `npm ci` installs `@php-wasm/web-8-3@3.1.48` and
`worker/scripts/prepare-runtime.mjs` stages both files into a gitignored
`worker/.php-wasm/`, verifying their upstream sizes and SHA-256 digests and
applying the one required patch `Module['Asyncify'] = Asyncify;` to the glue.
(The MVP as originally built carried them committed under `worker/vendor/`;
that is what changed, not the artifact.) JSPI is not shipped — the transaction seam
requires synchronous guest re-entry, which only Asyncify provides. Boot uses
`@php-wasm/universal`'s `loadPHPRuntime` with an `instantiateWasm` hook that
(a) hands Wrangler's precompiled wasm module to Emscripten and (b) replaces the
`env.__asyncjs__js_module_onMessage` import with the tagged dispatcher below.
Ported from the spike's host nearly verbatim.

## PHP↔JS protocol

### Doors

One wasm import, dispatched on the first byte of the message string:

- `'!'` **sync**: JS handler runs synchronously, reply written into guest
  memory, no Asyncify. Used for SQL and config.
- `'~'` **park**: JS receives the message plus a `reply(str)` callback that
  resumes the guest *synchronously from whatever JS stack frame calls it*.
  Used for the turn loop and transactions.
- anything else: stock php-wasm behavior (unused by us).

Guest-side helpers (in the runtime prelude, not customer-visible):
`\Atoms\Cf\host_sync(array $req): array` and
`\Atoms\Cf\host_park(array $req): array` — JSON-encode with `'!'`/`'~'`
prefix, JSON-decode the reply. Every reply is an object with `ok: true` or
`ok: false, error: {code, message}`; helpers throw `\RuntimeException` on
`ok:false` unless the op defines otherwise.

### Sync ops (`'!'`)

- `{"op":"sql.exec","sql":string,"bindings":[...],"mode":"rows"|"run"}`
  → `{"ok":true,"rows":[{col:val,...}...],"rows_read":int,"rows_written":int,
  "last_insert_rowid":int64tag}`
  Bindings are positional, already int64-tagged. `mode:"rows"` returns all
  rows; `"run"` returns counters only. Named parameters are rewritten to
  positional **in PHP** before crossing.
  `last_insert_rowid` is present **only when `rows_written > 0`**, and an
  intercepted PRAGMA never carries it. The guest caches the last value it was
  given and must treat the key's absence as "unchanged": PDO's contract is that
  `lastInsertId()` keeps reporting the last insert across any number of
  intervening reads, so a `0` sent after a plain `SELECT` would silently break
  `INSERT parent; SELECT …; INSERT child(parent_id = lastInsertId())`.
- `{"op":"config.get","key":string}` → `{"ok":true,"value":json|null}`.
  JS resolves from an allowlisted view of `env` (see Config).
- `{"op":"meta.get","key":string}` / `{"op":"meta.set","key":string,"value":string}`
  → runtime metadata in `__atoms_meta` (used by the host itself; PHP uses it
  only through the PRAGMA emulation below).
- `{"op":"log","level":string,"fields":{...}}` → `{"ok":true}` — structured
  log line emitted by JS `console.log` as JSON.
- `{"op":"dispatch.enqueue","body":string,"job":string}` →
  `{"ok":true,"buffered":bool}` — the sync half of `dispatch()` (M2). `body` is
  the complete `job` callback request body, already `json_encode()`d by the
  guest; `job` is a label only (the dispatched class's FQCN), never used to
  build the request. `buffered:true` means a database transaction is open and
  delivery is deferred to commit; `buffered:false` means the signed POST has
  already been initiated (not awaited — see §The callback channel). Genuinely
  synchronous: it validates and either buffers or starts the fetch, and never
  throws out of the sync door. Error codes: `callback_not_configured`,
  `callback_unsigned`, `callback_body_invalid`, `callback_too_large`,
  `dispatch_limit` (more than `ATOMS_MAX_DISPATCHES_PER_TURN` in one turn).
- `{"op":"ws.send","conn":string,"payload"?:string,"payload_b64"?:string}` →
  `{"ok":true,"bytes":int,"binary":bool}` — send one frame on an accepted
  WebSocket. Exactly one of `payload` (text frame) / `payload_b64` (binary
  frame, base64) must be present — this is the opcode rule (§The WebSocket
  seam). `ws_conn_gone` when `conn` no longer resolves to a socket.
- `{"op":"ws.close","conn":string,"code"?:int,"reason"?:string}` →
  `{"ok":true}` (or `{"ok":true,"already_gone":true}` when the connection was
  already gone — closing a gone connection is a defined, silent success, never
  an error).
- `{"op":"ws.broadcast","channel":string,"frame":string}` →
  `{"ok":true,"delivered":int,"failed":int}` — `frame` is the complete wire
  text, already `json_encode()`d by the guest; the host fans it out to every
  socket tagged for `channel` without ever parsing or re-encoding it (§The
  WebSocket seam, the int64 rule). `ws_fanout_limit` when the channel has more
  than `ATOMS_WS_MAX_BROADCAST_SOCKETS` sockets — a refusal, never a truncated
  send.
- `{"op":"timer.schedule","name":string,"due_at_ms":int}` → `{"ok":true}` —
  schedule (or replace) this Atom's timer `name`. `timer_invalid_name` (empty
  or over `ATOMS_TIMER_NAME_MAX_BYTES`); `timer_limit` (this Atom already has
  `ATOMS_TIMERS_MAX` scheduled timers, reply carries `{"count":int}`).
- `{"op":"timer.cancel","name":string}` → `{"ok":true}` — idempotent:
  cancelling a name with no pending timer is a silent success.
- `{"op":"timer.get","name":string}` → `{"ok":true,"due_at_ms":int|null}` —
  `due_at_ms` is a plain JSON number (epoch milliseconds), not int64-tagged:
  always far inside 2^53 for any timer a customer could plausibly schedule.

The three `timer.*` ops and the three `ws.*` ops run through
`ctx.storage.sql`/`ctx.getWebSockets()` directly and so are available inside an
open transaction exactly like `sql.exec` is — `timer.schedule`/`timer.cancel`
land on the same connection and roll back with it; `ws.send`/`ws.close`/
`ws.broadcast` are **not** gated on transaction state at all (see §The
WebSocket seam's transaction hazard, below).

### Park ops (`'~'`)

- `{"op":"turn.await","result": <turn-result|null>}` — parks between turns.
  `result` reports the *previous* turn's outcome (null on first park after
  boot). Resumed with a turn envelope carrying `"kind"` plus that kind's
  fields:
  - `{"kind":"invoke","method":string,"args":[...]}`
  - `{"kind":"shutdown"}`
  - `{"kind":"ws.connect","conn":{"id":string,"channels":[string,...]},"params":{string:string,...}}`
  - `{"kind":"ws.message","conn":{...},"payload":string,"binary":bool,"encoding":"utf8"|"base64"}`
  - `{"kind":"ws.close","conn":{...},"code":int,"reason":string,"wasClean":bool}`
  - `{"kind":"timer","name":string}`
- `{"op":"tx.begin"}` — host calls `ctx.storage.transactionSync(cb)` and
  resumes PHP **inside** `cb` with `{"ok":true}`.
- `{"op":"tx.commit"}` — parks so `cb` can return (committing); host resumes
  PHP after `transactionSync` returns with `{"ok":true,"committed":true}`.
- `{"op":"tx.rollback"}` — parks; host throws a sentinel inside `cb` so
  Cloudflare discards the write set, catches it outside, resumes with
  `{"ok":true,"rolledBack":true}`.
- `{"op":"app.call","body":string}` — the park half of `app()` (M2): see
  §The callback channel for the full request/reply shape.

While a transaction is open, `sql.exec` runs inside the callback's scope on
the same connection (read-your-own-writes). The host rejects any park op other
than `tx.commit`/`tx.rollback`/`turn.await` while a transaction is open, and
rejects `tx.begin` when one is already open (PHP's `Database::transaction()`
already guards nesting; the host guard is defense in depth). `app.call` is
named explicitly in the rejection's message
(`bridge.js`'s `TransactionMachine.drain()`):

> `park op "app.call" is not allowed while a database transaction is open: the
> transaction runs inside ctx.storage.transactionSync(), whose callback cannot
> await. Only tx.commit and tx.rollback are.`

Rejecting `app.call` there is **safe** in a way rejecting `turn.await` is
not: the guest has somewhere to go. The rejection becomes a PHP exception,
which propagates out through `Database::transaction()`'s own catch, which
issues `tx.rollback`, which the machine accepts — the existing mechanism
handles it end to end. `CallbackAppProxy` also refuses guest-side, before
encoding anything, so in the ordinary case no request ever reaches this
host-side check at all; it exists as defence in depth against a prelude bug.

`turn.await` is the one park op that is *not* rejected there, because it is the
turn boundary rather than a protocol violation: rejecting it would strand a
guest that has nowhere else to park. A turn that ends with a transaction still
open (a customer forgetting `commit()`, or catching an exception above the
frame that opened it) is settled instead — the write set is discarded and the
turn reports `atom_exception`. The runtime prelude does this itself before it
reaches the boundary; the host's handling of `turn.await` inside an open
transaction is the defence in depth, and reports `internal`. Neither path may
poison the residency: an application bug the host cannot see must not become a
retryable 500 that re-kills the Atom on every retry.

### Turn-result envelope

```json
{"ok":true,"result":<Serializer-normalized value>}
{"ok":false,"error":{"code":"atom_exception","message":"...","class":"FQCN"}}
```

`code` values: `atom_exception` (customer method threw), `method_not_found`,
`atom_not_found` (type absent from manifest), `internal` (runtime bug),
`turn_deadline_exceeded` (an uncaught `app()` deadline overrun — the only one
of these that maps to a distinct HTTP status, 504 retryable; see §The turn
deadline). Traces are sanitized: never sent to the client, logged server-side
only.

**WebSocket and timer turns.** A `ws.connect`/`ws.message`/`ws.close`/`timer`
turn's success envelope is always `{"ok":true,"result":null}` — the handlers
(`onConnect`/`onMessage`/`onDisconnect`/`onTimer`) return `void`. An uncaught
exception in one of them is logged server-side (`atoms.ws.turn_failed` /
equivalent) and becomes an ordinary `atom_exception` **turn-result** envelope,
exactly like a failed invoke — but because there is no HTTP client on the
other end of a socket or alarm event, that envelope is **never relayed to the
socket peer** in any form (not the message, not the class, not a generic
"error" frame) and never surfaces anywhere but the host's structured log. It
also does not close the socket and does not poison the residency: "the turn
loop never throws" holds for `run_ws_turn()`/`run_timer_turn()` exactly as it
does for `run_turn()`.

**The turn loop never throws.** Every failure a turn can produce — including
the failures of the boundary itself — is an envelope, because a throw out of
the loop unwinds the single `php.run()` and destroys the residency. Two of
those failures come from the JSON codec rather than from customer code, and
both are guarded: a return value that `json_encode()` cannot carry (invalid
UTF-8, recursive, or nested past the encoder's depth limit) becomes
`atom_exception`, and a turn envelope the guest's `json_decode()` cannot read
(args nested past *its* depth limit) becomes `internal`. The Worker also
refuses `args` nested deeper than `ATOMS_MAX_JSON_DEPTH` with a 400 before any
DO is addressed, so the client-facing failure names its cause.

### Int64 tagging

JSON numbers are exact only through 2^53−1. Any integer whose absolute value
exceeds 2^53−1 crosses the boundary as
`{"$atoms_int64":"<decimal string>"}` — in bindings, result rows,
`last_insert_rowid`, method args, and method results. JS uses `BigInt`
internally (`ctx.storage.sql` returns bigint for large ints); PHP decodes to
native int (64-bit safe in this build). Encoding is applied recursively by a
shared codec on each side. A value tagged this way that exceeds int64 range is
an error, not a truncation.

**The opaque-body invariant (M2).** `app.call` and `dispatch.enqueue` carry
their request/response as opaque JSON **strings** (`body`), not as structured
values, and this wire deliberately does **not** use the int64 tag above. The
`body` a guest hands the host is already `json_encode()`d — bare JSON numbers,
PHP-exact across the full signed 64-bit range on this build — and the host
never `JSON.parse`s or re-encodes it: `callbacks.js` turns it into bytes with
`TextEncoder.encode()`, signs those exact bytes, and sends those exact bytes;
the response text is relayed to the guest verbatim. "Signed ≡ sent" holds by
construction, not by discipline, and no JavaScript value is ever derived from
a callback body's contents. This is deliberately **stricter** than the
PHP↔JS wire above, where the host *does* decode SQL values and the int64 tag
exists precisely because JS has to touch them: `JSON.parse('{"n":
9223372036854775807}')` silently rounds in JS, so a host-side parse-and-
re-encode of a callback body would corrupt a customer's integer and
invalidate the signature computed over the corrupted bytes. The one guard is
a check for a lone UTF-16 surrogate (which `TextEncoder` would otherwise
silently replace with U+FFFD, making sent ≢ what PHP built even though
signed ≡ sent stayed true) — PHP's `json_encode()` cannot produce one, so
this can only ever fire on a prelude bug, and it fails the op with
`callback_body_invalid` rather than sending a corrupted body. `broadcast()`'s
`frame` follows the identical rule for a different reason: the guest builds
the complete wire frame with `json_encode()` and the host only fans the
string out, so a wide integer in a broadcast payload reaches the browser
exactly as PHP wrote it.

## The callback channel

`app()` and `dispatch()` (M2) both cross to the monolith over one Ed25519-
signed HTTP channel, implemented in `src/callbacks.js`'s `CallbackChannel` —
one instance per DO, alongside `Bridge` and `TransactionMachine`.

### Configuration and the three states

Two env vars, resolved in `config.js`, neither defaulted to a usable value:

| variable | default | meaning |
|---|---|---|
| `ATOMS_CALLBACK_URL` | `''` (unconfigured) | the monolith's callback endpoint |
| `ATOMS_CALLBACK_SIGNING_KEY` | `''` | base64 of the 32-byte Ed25519 seed |

`loadConfig()` classifies the pair into `callbackState`, and stays **total** —
it never throws, because `/healthz` must answer on a misconfigured Worker:

| `ATOMS_CALLBACK_URL` | `ATOMS_CALLBACK_SIGNING_KEY` | `callbackState` | `app()`/`dispatch()` |
|---|---|---|---|
| unset | anything | `unconfigured` | fail `callback_not_configured` → **ATOMS-E080** |
| set, invalid or non-loopback `http:` | anything | `misconfigured` | fail `callback_unsigned` → **ATOMS-E081** |
| set, valid | unset, or not 32 bytes of base64 | `misconfigured` | fail `callback_unsigned` → **ATOMS-E081** |
| set, valid | 32 bytes of base64 | `configured` | proceed |

`ATOMS_CALLBACK_URL` must be an absolute URL with scheme `https:`, or `https:`
scheme's exception `http:` only when the host is `127.0.0.1`, `localhost` or
`[::1]` — plain `http` to a public host would send customer arguments in the
clear (the signature protects integrity and authenticity, never
confidentiality); the loopback exemption is what keeps the conformance harness
and `atoms dev` legal. `ATOMS_CALLBACK_SIGNING_KEY` is added to the default
`ATOMS_CONFIG_ENV_DENY_KEYS`, alongside `ATOMS_APP_KEY`, so a misconfigured
`ATOMS_CONFIG_ENV_KEYS` can never expose it through `$this->config()`.

**Never send unsigned.** There is no "development mode" that skips the
signature — a monolith with `CallbackKernel` mounted would reject an unsigned
request anyway (`ATOMS-E064`), so sending one would only make a security
control look optional.

### Ed25519 signing

The Worker is the signer, using platform WebCrypto — no `@noble/ed25519`, no
fallback path. `ATOMS_CALLBACK_SIGNING_KEY` is the base64 of the raw 32-byte
Ed25519 **seed**; `callbacks.js` prepends the fixed 16-byte PKCS8 DER prefix
`302e020100300506032b657004220420` and imports with
`crypto.subtle.importKey('pkcs8', …, 'Ed25519', /* extractable */ false,
['sign'])`, memoized as a promise for the life of the residency. `extractable:
false` and the fact that the guest never sees the key material are a security
property worth stating plainly: **the key never enters wasm.** The guest
builds callback bodies; the host signs them. A customer Atom that reads
arbitrary guest memory still cannot obtain the signing key.

One request's signature (`signRequest()`):

```js
const ts    = String(Math.floor(Date.now() / 1000));
const nonce = toHex(crypto.getRandomValues(new Uint8Array(16)));   // 32 lowercase hex
const msg   = encode(`v1\n${ts}\n${nonce}\n`) + bodyBytes;
const sig   = await crypto.subtle.sign('Ed25519', key, msg);
```

Headers sent with every callback POST: `content-type: application/json`,
`x-atoms-signature` (base64), `x-atoms-timestamp` (unix seconds),
`x-atoms-nonce` (32 lowercase hex), `x-atoms-kind` (`methods` for `app()`,
`job` for `dispatch()`). This is exactly the shape `docs/conventions.md`
§Callback signing and `Atoms\Client\Callback\CallbackKernel` verify.

### Size caps

`ATOMS_CALLBACK_MAX_REQUEST_BYTES` (default 1048576) and
`ATOMS_CALLBACK_MAX_RESPONSE_BYTES` (default 1048576, from `config.js`) bound
the request and response bodies; over either is `callback_too_large`. The
response cap exists because the reply body is copied into guest memory by the
door's `writeReply()`, so unbounded is not an option.

### `dispatch()` delivery semantics

- **Immediate initiation.** Outside a transaction, `enqueueJob()` starts the
  signed POST without awaiting it (`startDelivery()`/`deliverJob()`) and
  registers the promise on the current turn's collector; the sync door replies
  `{"ok":true,"buffered":false}` before the POST completes. Each delivery's
  abort bound is `ATOMS_CALLBACK_TIMEOUT_MS` **flat** — unlike `app()`'s
  `min(remaining, ATOMS_CALLBACK_TIMEOUT_MS)` (§The turn deadline) — because a
  fire-and-forget delivery is not part of the turn's *awaiting* budget: the
  turn is not waiting on it.
- **Transaction buffering.** Inside a transaction, the body is pushed onto
  `txBuffer` instead (`{"ok":true,"buffered":true}`). `TransactionMachine`
  calls `onTransactionCommit()` — which moves the buffer into in-flight
  delivery — only after Cloudflare's `transactionSync` callback has actually
  returned (the write set is durable); `onTransactionRollback()` — which drops
  the buffer — on rollback or on an abandoned transaction settled at the turn
  boundary. A job dispatched inside a rolled-back transaction is dropped for
  the same reason the row it referenced never existed: **the job is as durable
  as the rows next to it.**
- **Settled before the response, never before.** `atom-do.js`'s
  `settlePostTurn()` awaits every promise the turn's collector accumulated
  (`Promise.allSettled`) **outside the turn mutex** but **before** that
  invoke/ws/timer turn's own response goes out. Concretely: the *next* turn on
  this residency may already be running guest code while this turn's
  deliveries are still in flight, but a client that received this turn's 200
  knows its jobs left the Worker. `dispatch()` from an uncaught throw is
  therefore delivered exactly like a non-transactional SQL write survives one
  — dispatching outside a transaction and then throwing still delivers.
- **At-most-once, unordered, unretried.** `deliverJob()` never retries. A
  transport failure, a timeout, or a non-2xx response is logged
  (`atoms.callback.delivery_failed`, with `job`/`reason`/`status`/`elapsed_ms`)
  and dropped — silently from the customer's point of view, because
  `dispatch(): void` is frozen ABI and cannot report a delivery failure
  without becoming a blocking call. Initiation failures (channel not
  configured, no signing key, an unencodable job, `dispatch_limit`) are the
  opposite: loud, and thrown from `dispatch()` itself, because they are
  programming/configuration errors discoverable at the call site.
- **The future upgrade.** An at-least-once outbox (`__atoms_outbox` rows
  written inside the turn's own transaction, drained by a re-arming alarm with
  backoff) is the documented successor to this fire-and-forget delivery. M2
  does not build it; naming it here is what should stop a future "fix" of this
  gap from becoming an ad hoc retry loop bolted onto the fire-and-forget path.

### Exception surface

`packages/core/resources/errors.json`'s **E08x block** ("worker runtime
seams"): `ATOMS-E080` `CallbackChannelNotConfigured`, `ATOMS-E081`
`CallbackSigningKeyUnusable`, `ATOMS-E082` `CallbackInTransaction`, `ATOMS-E083`
`CallbackRequestFailed`, `ATOMS-E084` `JobNotEncodable`. A turn-deadline
overrun reuses the pre-existing `ATOMS-E061` (`TurnDeadlineExceeded`) rather
than minting a new code — see §The turn deadline. Every one of these is
raised from `worker/php/runtime/` (`CallbackNotConfigured.php`,
`CallbackUnsigned.php`, `CallbackInTransaction.php`, `CallbackFailed.php`,
`JobNotEncodable.php`, `TurnDeadlineExceeded.php`), formatted through
`ErrorCatalog::format()` so the message and fix line always match the
catalog — unlike `AtomsNotSupported`, which predates the catalog and does not.
`CallbackInTransaction` (E082) is raised twice: guest-side in
`CallbackAppProxy::__call()` before anything is encoded (the customer gets a
clean exception and no request ever leaves the Worker), and host-side when the
`tx_state` reply is mapped by `CallbackChannel::exceptionFor()` (defence in
depth against a prelude bug).

### `manifest_hash` — omitted in M2

The `methods` request body carries no `manifest_hash` field.
`CallbackKernel::handleMethods()` never reads one, and nothing in `packages/`
consumes a `manifest_hash` from a callback body — sending one would mean
inventing a value with no consumer, in a field whose name is reserved for
version-skew detection. Wiring it up needs `atoms build` to write
`manifest_hash` into `manifest.json` and `bundle-from-cli.mjs` to carry it into
the host manifest; that is follow-up work, not part of this channel.

### The result-hydration gap

`app()->foo()` returns the **decoded wire tree** — `json_decode($responseBody,
true)['result']` — not hydrated back into `Payload` DTOs, `\DateTimeImmutable`
or `\BackedEnum` return values. This is a real fidelity gap against
`Atoms\Testing\FakeAppProxy`, which round-trips the return value through
reflection on the Methods class's declared return type. Scalars, arrays and
string-keyed maps — the overwhelming majority of Methods return types — are
unaffected; `\DateTimeImmutable` comes back as its RFC 3339 string and a
backed enum as its backed value, both re-hydratable by the customer in one
line. Closing this gap needs the manifest to carry `methods[].return` and the
`shared` list (to decide which return types are hydratable in the guest
bundle at all) into the host manifest — a design question, not part of M2.

## The turn deadline

**The contract, in three sentences:** a turn has a budget for time spent
*awaiting the callback channel*. When the budget is exhausted, the in-flight
callback is aborted and `app()` throws `Atoms\Cf\TurnDeadlineExceeded` in the
Atom; every later `app()` in the same turn fails immediately with the same
exception. **Everything the turn wrote before that point stays durable**,
including writes from transactions that had already committed. If the
exception is not caught, the turn reports `turn_deadline_exceeded` and the
Atom stays resident and healthy.

### What the budget is, and where it is measured

- **Starts** at turn dispatch — `beginTurn(ATOMS_TURN_DEADLINE_MS, collector)`
  runs after activation and before `runTurn()` delivers the envelope, so the
  budget exists before any guest code can consume it, for `invoke`, `ws.*` and
  `timer` turns alike.
- **Measured host-side** with `Date.now()`, at the await boundaries inside
  `serviceAppCall()` — where the clock is known to advance on both local and
  deployed workerd (appendix item 3: Cloudflare moves time forward only on
  I/O, and a callback POST is I/O).
- **Per-call bound**: `min(remaining, ATOMS_CALLBACK_TIMEOUT_MS)`, where
  `remaining = deadlineMs - (Date.now() - startedAt)`. If `remaining <= 0` the
  fetch is **not started at all** — the reply is `turn_deadline_exceeded`
  immediately, because starting a doomed request would still reach the
  monolith and enqueue work for a turn that has already failed.
- **Two codes, disambiguated by re-reading the clock after the abort:**

  | situation | reply code | budget latched? |
  |---|---|---|
  | the per-POST bound elapsed, budget remains | `callback_timeout` | no |
  | the turn budget is exhausted | `turn_deadline_exceeded` | yes |

  When `ATOMS_CALLBACK_TIMEOUT_MS >= remaining` the two abort at the same
  instant; only the arithmetic (`elapsed >= budget.deadlineMs`?) tells them
  apart. Conflating them would mean a monolith that is merely slow on one
  endpoint reporting "turn deadline exceeded" to the client and getting an
  opt-in retry it does not deserve.
- **Latched.** Once `turn_deadline_exceeded` has been produced, the budget is
  marked exhausted for the rest of the turn (`latch()`), so a later `app()`
  fails without a clock read and without another fetch — this is what stops a
  caught-and-retried `app()` loop from hammering the monolith once the turn is
  already out of time.
- **Reset** on the next `beginTurn()`.
- **Nothing guest-side ever reads a clock.** `app()` is told "you are out of
  budget"; it never computes elapsed time — the guest clock does not advance
  inside a turn on deployed workerd (appendix item 3), so it could not compute
  one honestly even if it tried.
- **Guest resumed, never stranded.** Every path out of `serviceAppCall()`
  calls `reply()`. The one exception is deliberate: if the PHP instance was
  discarded mid-await (a poison path racing the fetch), the residency's
  `phpGeneration` counter — captured before the await, compared after —
  no longer matches, and the reply is dropped rather than resuming a dead
  Emscripten module; this is logged as `atoms.callback.reply_after_discard`
  and the park unwinds through the existing "PHP runtime terminated mid-turn"
  path instead.
- **Uncaught → its own turn-result code.** `run_turn()` maps a caught
  `TurnDeadlineExceeded` to the turn-result code `turn_deadline_exceeded`
  rather than folding it into `atom_exception`, which the HTTP layer maps to
  **504, retryable: true** (see §Turn-result envelope) — `atoms/client`
  already maps `turn_deadline_exceeded` → `TurnDeadlineExceeded` and only
  retries when the call site opts in. `settle_open_transaction()` still runs
  first: an overrun inside a customer's `try` that leaks a transaction is
  still rolled back and still reported, and the deadline code still wins.
- **Caught → an ordinary turn.** A customer who writes
  `try { $x = $this->app()->price(); } catch (\RuntimeException) { $x =
  $cached; }` gets a 200 with the fallback result. This is deliberate: correct
  degradation code must not be punished.

### What is, and is not, enforceable

**Enforceable:** a bound on the time one turn spends *awaiting the callback
channel*, in aggregate (`ATOMS_TURN_DEADLINE_MS`, default 30000); a bound on
each individual callback (`ATOMS_CALLBACK_TIMEOUT_MS`, default 10000); a
guarantee that a stalled monolith cannot strand a parked guest.

**Not enforceable, and this spec does not imply otherwise:**

- **Any bound on guest CPU.** A turn is one synchronous guest run between park
  ops. JS has no stack frame during it, so no timer, no `AbortSignal`, and no
  amount of host code can interrupt it. This is not a gap in the
  implementation; it is what §Appendix already states about turn serialization
  and preemption.
- **A wall-clock bound on a turn that does no callback I/O.** Such a turn
  never yields to JS, so the only mechanism that ever acts on it is the
  platform's own CPU ceiling: measured at 30s, presenting as *"Durable Object
  exceeded its CPU time limit and was reset"*, seen by the client as a long
  hang and then `internal` (appendix item 3). `atoms/client` maps that to
  `AtomsRequestFailed`/retryable-internal, **not** to `TurnDeadlineExceeded`.
- **Charging CPU time against the budget.** On deployed workerd `Date.now()`
  is pinned to I/O, so a CPU-heavy stretch between two `app()` calls probably
  costs the budget nothing; locally it does advance across pure CPU (appendix
  item 3). Either way the budget's *meaning* is unchanged — it bounds
  awaiting — but the incidental CPU charge is platform-dependent and must not
  be relied on.

There is deliberately no turn-level timer alongside the per-fetch one: the
guest can only ever be suspended inside `app.call` (bounded by the per-fetch
`AbortSignal`), inside `tx.begin`/`tx.commit`/`tx.rollback`
(`transactionSync` is synchronous — no timer could fire there without
deadlocking), or at `turn.await` (the turn is already over). A turn-level
timer would fire in exactly the same places the per-fetch signal already
does, and in the one place that actually needs bounding — CPU — it could not
fire at all.

The existing `ATOMS_PARK_WAIT_TIMEOUT_MS` / `ATOMS_MAX_PARK_STEPS_PER_TURN` /
`ATOMS_MAX_TX_PARK_STEPS` guards are unchanged by any of this; they bound
*protocol* runaway, not customer time.

### Durability

Writes a turn made before an overrun are durable. A transaction that
committed before the overrun is committed. A transaction still open at the
moment of the overrun is rolled back by the existing `settle_open_transaction()`
path if the exception escapes it, and is untouched if the customer catches
inside it and commits. There is no partial-turn rollback and the runtime does
not pretend there is: the deadline overrun is the failure of *one park op*,
not a turn-termination event — the guest's PHP stack stays intact and the
turn continues normally if the exception is caught.

## Worker layout

Plain ESM JavaScript with JSDoc types (no build step beyond Wrangler; TS
migration is post-MVP). All operational values (timeouts, limits) come from
env vars with defaults resolved in one `config.js` module — no capacity
constants inline (workspace rule).

```
cloudflare/worker/
  src/
    index.js        # Worker entry: router, auth, error mapping, /ws upgrade
    atom-do.js      # AtomDurableObject class
    php-host.js     # boot + tagged-door dispatcher (ported from spike)
    bridge.js       # sql.exec/config.get/meta/log/dispatch.enqueue handlers,
                    #   tx state machine, delegates ws.*/timer.* to their modules
    callbacks.js     # CallbackChannel: app.call + dispatch.enqueue, Ed25519
                    #   signing, turn-deadline budget (M2)
    websockets.js    # WebSocketHost: ws.send/ws.close/ws.broadcast, the
                    #   {v,id,ch} attachment, connId -> socket memo (M2)
    timers.js        # TimersHost: timer.schedule/cancel/get, __atoms_timers,
                    #   the multiplexed alarm re-arm rule (M2)
    int64.js        # tagging codec (JS side)
    config.js       # env-derived settings with defaults
  php/
    runtime/        # Atoms\Cf\* prelude: host_sync/host_park, bootstrap loop,
                    # BridgeDatabase, AtomsPDO, CfAtomContext, int64 codec,
                    # dispatcher, error envelope, CallbackAppProxy/
                    # CallbackChannel + CallbackError subclasses (app()/
                    # dispatch()), CfConnection/CfMessage/ConnectionClosed
                    # (WebSockets), CfTimers + its typed exceptions (timers)
    atoms-core/     # verbatim copies from packages/core/src (repo root):
                    # Atom.php, Runtime/{AtomContext,LifecycleInvoker}.php,
                    # Database.php, Migrations/*, Serialization/*, Websocket/*,
                    # Timers/*, AtomJob.php, AtomMethods.php (+ whatever they
                    # require)
  fixtures/
    counter/        # fixture "customer app": manifest.json + Atom classes
                    #   + migrations (Counter, Vault, Room, Scheduler,
                    #   Jobs/Notify)
  .php-wasm/        # php_8_3.asyncify.{js,wasm}  (pinned; staged by
                    #   scripts/prepare-runtime.mjs, gitignored, never committed)
  scripts/          # build-bundle.mjs (assemble fixtures into bundle.json),
                    #   dev-with-callback.mjs (wrangler dev + a per-run
                    #   callback keypair, for the conformance callback checks)
  test/             # conformance suite (node, hits a running worker URL)
  wrangler.jsonc
  package.json
```

### Routing and auth

- `POST /invoke/:type/:id/:method` body `{"args":[...]}` →
  `200 {"result":..., "atom":{"type":...,"id":...}}` or
  `4xx/5xx {"error":{"code","message","retryable"}}`. Mirrors the existing
  platform invoke contract (`docs/platform/api-contract.md`)
  minus the `/v1/{customer}` prefix — single-tenant Worker.
- `GET /healthz` → `{"ok":true}` (no DO touch).
- `GET /debug/:type/:id/info` → residency info (constructions, turns, php
  boot ms, user_version, memory high-water, plus the `ws`/`timers`/callback
  debug blocks described below). Enabled only when `ATOMS_DEBUG_ENDPOINTS=1`.
- `GET /ws/:type/:id?channels=a,b,c&...` `Upgrade: websocket` → `101` carrying
  `webSocket`, or `4xx/5xx {"error":{...}}` in the same envelope shape as
  `/invoke` (M2). Validated in this order, all of it in `src/index.js` before
  any DO is addressed:
  1. `checkAuth()` — same bearer check as every other route.
  2. `request.method === 'GET'`, else `method_not_allowed`.
  3. `Upgrade: websocket` header present, else `invalid_request`.
  4. `validateType(type)` / `validateId(id, config)` — the same functions
     `/invoke` uses, so the id caps and the control-character/`\n`
     DO-name-collision guard are shared, not re-implemented.
  5. `checkManifest(type)` → `unknown_atom_type` (404).
  6. The manifest entry's `websocket` flag: explicit `false` → `not_supported`
     (501), naming the type; absent or `true` → allowed.
  7. Parse and validate `?channels=` and the rest of the query string (caps
     from `config.js`) — see §The WebSocket seam.
  8. Forward to the DO: `index.js` cannot use the JSON envelope every other
     route uses (`callDurableObject()`) because an upgrade needs the real
     `Request`/`Response` pair for workerd to hand back a `webSocket`, so it
     builds `stub.fetch(new Request('https://atoms.internal/ws?call=' +
     encodeURIComponent(JSON.stringify({type, id, params, channels})),
     request))` — `new Request(url, request)` preserves the method and headers
     (including `Upgrade`) from the original request.
  **Auth posture:** the bearer header is required exactly like every other
  route when `ATOMS_APP_KEY` is set — no query-parameter credential, no
  `Sec-WebSocket-Protocol` smuggling. A browser's `new WebSocket(url)` cannot
  set an `Authorization` header; that is a **known gap**, not worked around.
  The designated future answer is `Atoms\Client\Tickets\TicketClient`
  (`POST /tickets/{type}/{id}` → a short-lived ticket), already sketched in
  `packages/client/` and explicitly **not implemented** by any Worker route in
  M2.

DO identity: `idFromName(type + "\n" + id)`. Atom type must exist in the
bundle manifest before any DO is touched. Method-name validation happens
in PHP against the manifest.

### AtomDurableObject lifecycle

- Wrangler config uses the declarative class export with
  `storage: "sqlite"` and binding name `ATOMS` (shape from the production
  plan §"One generic Durable Object class").
- First event of a residency runs the activation gate inside
  `blockConcurrencyWhile`: boot PHP (instantiateWasm), install doors, write
  runtime + bundle files into MEMFS, start the PHP bootstrap (one `php.run()`
  that never returns until shutdown), which: validates/records
  `__atoms_meta` (canonical type, id, abi versions), applies pending
  migrations via the real `Atoms\Migrations\Migrator` inside a transaction,
  constructs the customer Atom (`new $class($id, $context)`), calls
  `LifecycleInvoker::activate()`, then parks at `turn.await`.
- Mismatched stored type/id vs request → 409, no PHP dispatch.
- Turns are strictly serialized with a promise-chain mutex in the DO.
- A turn: resume parked loop with the invoke envelope → PHP dispatches to the
  Atom method (args/result through `Atoms\Serialization\Serializer`) → PHP
  parks again at `turn.await` carrying the result → DO responds. Durability:
  everything ran on `ctx.storage.sql`; Cloudflare's output gate holds the
  response until writes are durable.
- If `php.run()` ever returns or throws (guest fatal), the residency is
  poisoned: respond `{"error":{"code":"internal"}}` 500, discard the PHP
  instance, and let the next request re-activate from durable state.
- Hibernation needs nothing for the invoke path: each completed turn is
  durable; `onActivation()` runs once per residency by construction. There is
  still no deactivation hook on eviction (best-effort by contract) — nothing
  in M2 changes that.

**The WebSocket accept path (M2).** `AtomDurableObject.fetch()` branches on
`url.pathname === '/ws'` before it ever reads a JSON body (an upgrade has
none), and runs the whole accept as one unit on the turn mutex
(`this.enqueue()`): run the **same** `ensureActive()` activation gate `fetch()`
uses; mint `connId = crypto.randomUUID()` and the `{v,id,ch}` attachment
host-side; `ctx.acceptWebSocket(server, tags)`, then
`server.serializeAttachment(attachment)`, then memoize the socket — in that
order, so a frame arriving the instant after accept still finds a readable
attachment; dispatch the `ws.connect` turn (`onConnect`); return the `101`
**after** that turn completes (a frame sent from inside `onConnect` still
reaches the client — measured, §Appendix). A throw here (residency poisoned
mid-`onConnect`) closes the socket with `1011` and returns an error response:
a connection whose `onConnect` never ran must not exist.

**The wake path (M2).** `webSocketMessage`/`webSocketClose`/`webSocketError`
are gated by `blockConcurrencyWhile` exactly the way `fetch()` is (measured —
§Appendix), so there is no WebSocket-specific "is this residency warm?" check
anywhere. Each reads the socket's `{v,id,ch}` attachment; an unreadable
attachment or a `v` this deployment does not understand closes the socket
(`1011`/`1012` respectively) without dispatching a turn — a socket accepted by
deployment *N* handed to deployment *N+1* after a wake is a cross-version wire
format, not an in-process struct, and the host must not guess at it. Identity
for the turn comes from **`__atoms_meta`**, not from any in-memory field: a
wake may be the first event a fresh JS instance has ever seen, and
`__atoms_meta` is written at the end of every successful activation. The event
then runs through the **same** activation gate and the **same** turn mutex as
every invoke — `ensureActive()` re-runs `onActivation()` exactly as it does
for a cold invoke, which is the existing residency contract, not a
WebSocket-specific one. `onConnect` fires from exactly the one place in the
accept path above and **only** from there — no wake path can ever reach it, so
it is guaranteed to fire exactly once per connection lifetime. Sockets are
left **open** across a poisoned residency (`discardPhp()` clears the
residency-local connId→socket memo but never closes a socket): poisoning is
recoverable, and the next frame re-activates from durable state exactly like
an invoke would.

**The alarm wake path (M2).** `alarm()` is the mechanism that wakes an
evicted residency with **no HTTP request involved at all** — see §Timers.

Durability of a WebSocket/timer turn's writes follows the same rule as an
invoke's: everything ran on `ctx.storage.sql`, and — for `ws.connect`/
`ws.message`/`ws.close` — the accept/wake path's outstanding DO event (`fetch`
handler for accept, the hibernation callback for a wake) is what makes it
impossible for a callback fetch or a `dispatch()` delivery to run outside an
awaited event: the DO is never evicted mid-event, only between them.

### SQL bridge details

- `PRAGMA user_version` (read and `= N` write) is intercepted **in JS** and
  mapped to the `user_version` key in `__atoms_meta`, so the unmodified
  `Migrator` works. `PRAGMA foreign_keys=ON` passes through. Other
  connection pragmas (`journal_mode`, `synchronous`, `busy_timeout`) return
  synthetic no-op answers. Everything else goes to `ctx.storage.sql.exec`
  unchanged.
- Customer SQL touching `__atoms_*` tables is rejected (`reserved_table`),
  checked in the JS handler against the full request text (`sql.exec` runs
  every statement in the string). The check is lexical, not a parse — that much
  is acceptable for the MVP — but it must not be defeatable by ordinary SQL
  punctuation, so the text is scanned the way SQLite's own tokenizer reads it:
  string literals are blanked, comments are removed, and quoted/bracketed
  identifiers are kept so `"__atoms_meta"` is still caught. A single regex
  pairing quotes globally is *not* sufficient — an apostrophe inside a `--`
  comment desynchronises it from the parser and blanks the following statement
  out of the checked text. The consequence of a miss is not just disclosure: a
  write to `__atoms_meta.atom_type` makes every later activation 409 with
  `identity_conflict`, and the MVP router has no reset route.
- SQL errors map to `{"ok":false,"error":{"code":"sql_error","message",
  "sqlstate"}}` and become `PDOException`s in PHP with a real
  `errorInfo()` triple.

### PHP-side db()

`Atoms\Cf\BridgeDatabase implements Atoms\Database`:

- `query()`/`execute()` prepare-by-rewriting named→positional, tag int64,
  call `sql.exec`, return assoc rows / affected count.
- `transaction(callable)` mirrors `SqliteDatabase`'s nesting guard, then
  `tx.begin` → `$fn($this)` → `tx.commit`, catching to `tx.rollback` +
  rethrow.
- `pdo()` returns `Atoms\Cf\AtomsPDO extends \PDO` (spike shim, hardened):
  `prepare/execute/query/exec/fetch/fetchAll/fetchColumn/lastInsertId/
  beginTransaction/commit/rollBack/inTransaction/quote/errorCode/errorInfo`
  route to the bridge; unsupported members throw
  `Atoms\Cf\AtomsNotSupported extends \PDOException` — never a silent
  carrier-database answer. The dummy carrier connection (`sqlite::memory:`)
  exists only to satisfy the `\PDO` constructor.

### PHP-side `app()` and `dispatch()` (M2)

`CfAtomContext::app()` returns a lazily-built `CallbackAppProxy`
(`worker/php/runtime/CallbackAppProxy.php`); `dispatch()` is a plain method on
`CfAtomContext` itself. Full wire shapes, config and delivery semantics are in
§The callback channel; this is the guest-side implementation, the sibling of
§PHP-side `db()` above.

- **`app()->{method}(...$args)`.** `__call()` checks
  `$this->bridge->inTransaction()` first and throws `CallbackInTransaction`
  (E082) before encoding anything — the primary guard; §PHP↔JS protocol's
  host-side `tx_state` rejection of `app.call` is defence in depth for the
  same rule. Arguments are normalized through `Atoms\Serialization\Serializer`
  into a JSON list (`array_values($arguments)`, matching the kernel's
  `array_values($payload['args'])`); the body is
  `{"atom":{"type":...,"id":...},"method":...,"args":[...]}`, built with
  `json_encode(..., JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)` —
  the zero-fraction flag so a `float` argument of `1.0` does not silently
  become a JSON integer. Crosses on `app.call` via `host_park_raw()` (mirrors
  `host_sync_raw()`, the SQL path's own raw-reply helper), so the proxy can
  see `error.code` and choose the right typed exception rather than getting
  `host_park()`'s generic `\RuntimeException`. A non-`200` reply is
  `CallbackFailed` (E083) carrying the kernel's own error code/message when
  the body decodes as one; a `200` with no `"result"` key is also E083 ("the
  monolith did not answer with a result envelope"); otherwise the method
  returns `json_decode($responseBody, true)['result']` — see the
  result-hydration gap in §The callback channel.
- **`dispatch(AtomJob $job)`.** Reads the job's constructor parameters by
  reflection and, for each one, requires a same-named **public,
  non-static** instance property — `JobNotEncodable` (E084) otherwise — then
  reads that property's value and normalizes it through `Serializer`. This is
  **exactly dual** to `Atoms\Client\Callback\CallbackKernel::constructJob()`,
  which does the reverse walk to reconstruct the job on the monolith side:
  same reflection, same source of truth (the constructor's parameter list),
  the map keyed by constructor **parameter name** on both sides. There is no
  runtime "is this an `AtomJob`" check on the guest side (unlike the kernel's
  `ATOMS-E033`): the frozen ABI's `dispatch(AtomJob $job)` parameter type
  already enforces it. The body is `{"job":FQCN,"args":{...}}` (an empty
  `args` is encoded as `new \stdClass()`, never `[]`, because the wire form is
  an object) and crosses on the sync `dispatch.enqueue` op via
  `host_sync_raw()`, carrying `job` as a label alongside `body` purely for the
  host's delivery-failure logs — never used to build the request (the
  opaque-body invariant).
- **`CallbackChannel::exceptionFor()`** (`worker/php/runtime/
  CallbackChannel.php`) is the one place both doors map a host door failure
  onto a typed exception, so `app()` (park door) and `dispatch()` (sync door)
  fail identically for the codes they share.

## The WebSocket seam

`Atom::onConnect/onMessage/onDisconnect` and `AtomContext::broadcast()` (M2),
implemented in `src/websockets.js`'s `WebSocketHost` (one instance per DO) plus
the prelude's `CfConnection`/`CfMessage`.

### The attachment format and its versioning rule

One JSON-safe object, written once at accept via `serializeAttachment()`,
never rewritten:

```json
{"v": 1, "id": "9f2c…-uuid", "ch": ["lobby", "room:7"]}
```

- `v` — format version, `1` for M2 (`websockets.js`'s `WS_ATTACHMENT_VERSION`,
  a protocol constant, not env-tunable — the same category as `BOOT_PROTOCOL`).
- `id` — the connection id, `crypto.randomUUID()`, minted **host-side** at
  accept. Exactly what `Connection::id()` returns to customer PHP.
- `ch` — the channel names this connection joined, in accepted order,
  already validated and de-duplicated.

Nothing else — no `params`, no atom type/id, no timestamps, and the hard
rule: **nothing derived from guest memory.** This is stated as the
correctness rule it is, not a style preference: after a wake the guest is a
*fresh interpreter* (a new `php.run()`, MEMFS rebuilt), so every PHP pointer,
object id or array offset from before the eviction refers to unrelated memory
in the new instance. A value like that does not fail loudly on read — it
reads as a valid-looking integer that means nothing, and the bug surfaces as
one customer's frames delivered to another customer's `Connection`. Making the
attachment carry identity only, minted and owned by the host, makes that class
of bug unrepresentable: neither `id` nor `ch` has any guest-side counterpart
that could go stale.

**Versioning rule.** `v` is bumped only when the meaning of an existing field
changes or a field is removed; a new optional field does not bump it, and
readers ignore unknown keys. A host that reads an attachment whose `v` it does
not understand **must not guess**: `atom-do.js`'s `wsEvent()` closes that
socket with `1012` (Service Restart) and logs, dispatching no turn — because
the attachment is a cross-version wire format (a socket accepted by
deployment *N*, handed to deployment *N+1* after a wake), not an in-process
struct.

Size: capped at `ATOMS_WS_MAX_ATTACHMENT_BYTES` (default 512, from
`config.js`) before `serializeAttachment()` — a connection whose channel list
would exceed it is refused at the upgrade with `invalid_request`, before
accept. 512 is deliberately far under both the measured local platform limit
(16384 bytes) and whatever smaller number production may enforce — see
§Appendix.

### Channel membership and tags

Channels are **fixed at connect time** from one query parameter,
`?channels=a,b,c` (comma-separated, validated and de-duplicated in
`index.js`). There is no join/leave API: the frozen `Connection` interface has
exactly `id()`, `send()`, `close()`, and none of them gained a method. Each
channel becomes a tag `ATOMS_WS_CHANNEL_TAG_PREFIX + name` (default `"ch:"`);
the connection itself gets one more tag, `ATOMS_WS_CONN_TAG_PREFIX + connId`
(default `"c:"`). `loadConfig()` asserts the two prefixes are disjoint (one is
never a prefix of the other) at startup, so a channel tag can never collide
with a connection tag for any name/id, whatever charset either uses.

Caps, all from `config.js`: `ATOMS_WS_MAX_CHANNELS` (default 8) channels per
connection; `ATOMS_WS_MAX_CHANNEL_NAME_BYTES` (default 64) per name; the
derived check `1 + channels.length ≤ ATOMS_WS_MAX_TAGS_PER_CONNECTION`
(default 10 — the measured platform ceiling on tags per hibernatable socket,
§Appendix), so the tag budget is stated once. Every violation is a named
`invalid_request` (400); channels are never silently truncated.

**No reserved "everyone" channel, deliberately.** No channel name reaches
every connection implicitly. A connection that joined no channels can still
be reached by `Connection::send()` and can still send, but receives no
broadcasts. To reach everyone, a customer joins a channel it names itself
(`?channels=all` works fine — the customer chose that name). The cost, stated
plainly: **a connection cannot change channels without reconnecting** —
membership is fixed at accept because tags are immutable after
`acceptWebSocket()` (there is no `setTags`), and "membership = tags" is what
makes `broadcast()` need no separate durable subscriber table.

### The two client-facing frame formats, and their asymmetry

| | Input | On the wire |
|---|---|---|
| `Connection::send(string $payload)` | a string | **verbatim** — no wrapper. The customer owns the framing. |
| `broadcast(string $channel, array $payload)` | an array | **wrapped**: `{"kind":"broadcast","channel":...,"payload":Serializer-normalized}` |

This is deliberate, not an inconsistency: `send()` hands the runtime bytes the
customer already framed, so wrapping them would corrupt a non-JSON customer
protocol; `broadcast()` hands the runtime a structure, so the runtime must
serialize it — and the moment it does, it must also say which channel the
frame came from, because a socket on more than one channel has no other way
to tell two broadcasts apart. `kind` lets a client distinguish a broadcast
frame from whatever else the Atom sends over `Connection::send()` on the same
socket. The guest builds the **entire** `broadcast()` frame with
`json_encode()` (`CfAtomContext::broadcast()`) and crosses it as one opaque
string on `ws.broadcast`; the host (`WebSocketHost.opBroadcast()`) never
parses or re-encodes it — the same int64 rule as the callback channel (§PHP↔JS
protocol → Int64 tagging): a `JSON.stringify()` of a structured payload would
silently round any integer past 2^53−1.

### Binary policy

**Inbound:** supported. `webSocketMessage` receives an `ArrayBuffer` for a
binary frame; the host base64-encodes it into the turn envelope
(`{"payload":"<b64>","binary":true,"encoding":"base64"}`); `CfMessage::
payload()` returns the `base64_decode()`d bytes and `isBinary()` returns
`true` — PHP strings are byte-safe, so this honours `payload(): string`
exactly.

**Outbound:** text if the payload is valid UTF-8, binary otherwise — the rule
in `CfConnection::send()` (`preg_match('//u', $payload)`). `host_call()`
JSON-encodes every sync request and `json_encode()` fails outright on invalid
UTF-8, so this is forced, not a preference: a payload that is valid UTF-8
crosses as `{"payload":...}` (`ws.send` → text frame); one that is not crosses
base64-encoded as `{"payload_b64":...}` (`ws.send` → binary `ArrayBuffer`
frame). This makes `send($rawBytes)` always arrive binary and
`send(json_encode(...))` always arrive text, deterministically, under the
customer's own control.

Both directions are capped by `ATOMS_WS_MAX_MESSAGE_BYTES` (default 131072,
inbound) / `ATOMS_WS_MAX_SEND_BYTES` (default 131072, outbound/broadcast),
measured on **decoded** bytes. An inbound frame over the cap is not dispatched
as a turn at all — the socket is closed with `1009` (Message Too Big) and
logged, because dispatching nothing while returning normally would leave the
peer believing a dropped frame was delivered.

### Dead-connection semantics

`Connection::send()` to a connection the host can no longer resolve
(`WebSocketHost.socketFor()` — a residency-local memo backed by
`ctx.getWebSockets(tagPrefix + connId)`, never persisted) throws
`Atoms\Cf\ConnectionClosed`, a typed, catchable `\RuntimeException` — not a
silent no-op, because `send(): void` gives the caller no other channel to
learn a message was not delivered. `Connection::close()` on an already-gone
connection is the opposite: a **silent success**, because asking an
already-closed thing to close got the outcome the caller wanted (and a second
platform-level `close()` does not throw either — measured, §Appendix).

**Honesty caveat.** The host can only detect "gone" when the id resolves to no
socket at all. If the socket is mid-teardown, `ws.send()` can still succeed
while the platform silently drops the frame (measured: `send()` inside
`webSocketClose` does not throw — §Appendix). So `send()` returning normally
means **accepted for delivery**, never **delivered** — the absence of
`ConnectionClosed` is not a delivery guarantee.

### WebSocket ops inside a transaction

`$conn->send()`/`close()`/`broadcast()` are sync (`'!'`) ops, and the host's
transaction guard only rejects *park* ops while a transaction is open
(§PHP↔JS protocol → Park ops) — so these execute immediately, even inside
`db()->transaction()`. This is a **documented hazard, not a bug**: a frame
sent inside a transaction that later rolls back **has already gone out**.
WebSocket sends are not transactional and are not buffered to commit, because
buffering would change `send()`'s timing semantics for every caller to guard
against a case the customer can avoid by moving the send after the commit.
`acceptWebSocket()` itself is impossible to call from inside a transaction —
it is host-side only, reachable through no guest op, and the upgrade path
runs strictly between turns through `enqueue()` — so this hazard is about
`send`/`close`/`broadcast`, never about accepting a connection.

## Timers

`$this->timers()` (M2), backed by the `__atoms_timers` table
(`CREATE TABLE IF NOT EXISTS __atoms_timers (name TEXT PRIMARY KEY, due_at_ms
INTEGER NOT NULL)`, created in `bridge.js`'s `ensureSchema()` alongside
`__atoms_meta`) and **one multiplexed Durable Object alarm per residency**,
implemented in `src/timers.js`'s `TimersHost`.

- **`schedule(name, at)`/`cancel(name)`** cross the sync `timer.schedule`/
  `timer.cancel` ops and run through `this.sql = ctx.storage.sql` — the exact
  reference `Bridge.opSqlExec` uses — so a schedule/cancel issued while a
  database transaction is open lands inside that same transaction and rolls
  back with it, exactly like an ordinary `sql.exec` write. `schedule()` with a
  name that already has a pending timer replaces it (`INSERT … ON CONFLICT
  (name) DO UPDATE`): at most one outstanding timer per name.
- **A single alarm, re-armed at turn end.** Whenever a turn's `timer.*` ops
  touch the table, `atom-do.js`'s post-turn hook calls
  `TimersHost.rearmIfTouched()`: `SELECT MIN(due_at_ms)`, then
  `ctx.storage.setAlarm(that value)` (a past value fires immediately) or
  `ctx.storage.deleteAlarm()` when nothing is scheduled. One alarm slot serves
  every timer this Atom has, however many are pending — there is no
  per-timer alarm.
- **At-most-once firing: delete before dispatch.** `atom-do.js`'s `alarm()`
  (`runAlarm()`) reads due rows **without booting PHP**
  (`TimersHost.dueRows()`), then for each row: **deletes it first**, *then*
  dispatches the `timer` turn. A throwing (or residency-poisoning) `onTimer`
  therefore still consumes the timer — this is deliberately **not** an
  at-least-once queue; a timer that fails is gone, not retried.
- **Batch bound per alarm run.** `ATOMS_TIMERS_MAX_PER_ALARM` (default 100)
  due rows are processed per `alarm()` invocation, oldest due first
  (`ORDER BY due_at_ms ASC, name ASC`). If more rows were due than the batch
  allows, the unconditional re-arm after the batch leaves `MIN(due_at_ms)`
  still in the past, so the platform fires again immediately — never an
  unbounded loop inside one `alarm()` call.
- **Timer turns are ordinary turns.** `runTimerTurn()` goes through the
  **same** `enqueue`/`ensureActive`/`beginTurn`/`runTurn`/`settleTurn`
  machinery as an invoke or a WebSocket turn: the turn-deadline budget
  applies, and `app()`/`dispatch()`/`broadcast()` all work identically from
  inside `onTimer`, including scheduling another timer from within it (the
  fixture's `chain-1` → `chain-2`). There is no HTTP response to hold, so a
  timer turn's `dispatch()` deliveries are still settled before `runAlarm()`
  moves to the next due row.
- **The alarm wakes an evicted residency with no HTTP request involved at
  all** — `alarm()` reads identity from `__atoms_meta` exactly like a
  WebSocket wake does, because it may be the first event a fresh JS instance
  has ever seen.
- **Per-Atom cap**, `ATOMS_TIMERS_MAX` (default 10000): counted only against a
  name that is not already scheduled, so replacing an existing timer's due
  time is never refused by it.
- **Error codes:** `ATOMS-E085` (`InvalidTimerName` — empty name, or over
  `ATOMS_TIMER_NAME_MAX_BYTES`, default 256) and `ATOMS-E086`
  (`TimerLimitExceeded`), both in the E08x block, both raised from
  `worker/php/runtime/CfTimers.php` mapping the host's `timer_invalid_name`/
  `timer_limit` sync-door codes. `cancel()`/`scheduledAt()` on a name with no
  pending timer are defined no-op successes, not errors.

## Bundle format (MVP)

`scripts/build-bundle.mjs` walks a fixture app directory and emits
`src/bundle.generated.js`: `export default {manifest, files}` where `files`
maps guest paths (`/app/...`, `/atoms/...`) to file contents, and `manifest`
is `{"atoms":{"Counter":{"class":"App\\Atoms\\Counter","file":"/app/Counter.php",
"migrations":["/app/migrations/001_init.sql", ...]}}, "abi":{"php":"8.3"}}`.
The DO writes `files` into MEMFS at boot. No customer PHP executes at build
time. This format is internal and versioned `bundle_format: 0`.

**`atoms build` integration (M3, 2026-08-09).** The module format above is
unchanged and remains what the host loads — it is the *deploy* artifact.
`atoms build`'s `bundle-{sha256}.tar.gz` + schema-1 `manifest.json` is the
*portable* artifact, and `scripts/bundle-from-cli.mjs` translates the second
into the first; `atoms deploy` runs it before `wrangler deploy`. Neither format
moved, so nothing under `src/` or `php/` changed and this section still
describes what the Worker loads. `build-bundle.mjs` is no longer a stand-in for
`atoms build`: it builds the conformance fixture, which is all it now claims.
See `docs/cloudflare-toolchain.md` §3.

## Fixture app (conformance subject)

`fixtures/counter/` defines four Atom types and one job class:

- `Counter` — `increment(int $by): int` (SQL update + returns new value),
  `getValue(): int`, `getStats(): array` (exercises Serializer arrays),
  in-memory `$turnsThisResidency` property (proves warm-residency),
  `onActivation()` writes an activation row (proves lifecycle),
  migration `001_init.sql` creating `counter_state`, migration
  `002_add_stats.sql` (proves ordered multi-migration); `notify(string $note)`
  dispatches `App\Jobs\Notify` (checks 16–17).
- `Vault` — `putBig(string $key, int $value): void`, `getBig(string $key): int`
  (int64 boundary cases through args, SQL, and results),
  `transfer(...)` using `db()->transaction()` with a forced-failure path
  (proves genuine rollback), plus one method using `db()->pdo()` directly
  (proves AtomsPDO); `echoViaApp(int $value): int` calling
  `$this->app()->echoBig($value)` (check 13), `appInsideTransaction()` (check
  14), `stallViaApp()`/`stallCaught()` (check 15's deadline overrun, uncaught
  and caught).
- `Room` — a **separate** type from `Counter`/`Vault` (not new methods on
  either, so checks 3/11/12's exact `turnsThisResidency` assertions are
  undisturbed by M2), manifest entry `"websocket": true`. `onConnect` records
  a `room_events` row and sends a `{"kind":"welcome",...}` frame;
  `onMessage`'s protocol is driven by the frame prefix (`echo:`, `bcast:`,
  `id?`, `poke:<connId>` — the last catching `ConnectionClosed` and recording
  the outcome); `onDisconnect` records a row; `stats(): array` is invocable
  over plain `/invoke`, which is how the suite observes the WebSocket side
  through the route it already trusts. Migration creates `room_events(kind,
  conn_id, detail, at)`.
- `Scheduler` — a fixture for the timer/alarm seam. `arm(name, delayMs)`
  schedules a timer `$delayMs` from now; `armInsideRollback(...)` schedules
  then throws inside a transaction (proves `timer.schedule` rolls back with
  everything else); `cancelTimer(name)`; `scheduledMs(name): ?int`;
  `timerLog(): array` reads a durable `scheduler_events` table its
  `onTimer($name)` hook writes to — except for a name starting with `boom`,
  which throws instead (proves at-most-once consumption of a failing timer),
  and `chain-1`, which reschedules `chain-2` from inside `onTimer` itself.
- `App\Jobs\Notify` (`fixtures/counter/app/Jobs/Notify.php`) — an `AtomJob`
  with promoted public `$atomId`/`$note` properties, the dispatch contract
  `dispatch()`'s encoder and `CallbackKernel::constructJob()` must agree on.

## Conformance suite

`test/conformance.mjs` runs against any base URL (`ATOMS_BASE_URL`), so the
same suite runs against `wrangler dev` and the deployed Worker. It is 24
checks: the original 12 (1–12, untouched, not renumbered, not weakened) plus
12 more added across M2's three waves.

1. healthz; 2. invoke + result envelope; 3. warm-residency (in-memory counter
increments across turns); 4. isolation between two IDs; 5. migrations applied
once, `user_version` correct, activation row present; 6. tx commit
read-your-own-write; 7. tx rollback discards observed write; 8. uncaught
customer exception → `atom_exception` envelope, next turn healthy;
9. int64 matrix (±2^31, ±2^53, ±(2^63−1)) through args/SQL/results/
`lastInsertId`; 10. reserved-table rejection; 11. turn serialization (two
concurrent invokes of a deliberately slow method interleave nowhere);
12. eviction/wake (≥12s idle in dev, ≥15s deployed): constructions increments,
durable state intact, in-memory state reset, `onActivation` re-ran.

**13–17 — the callback channel (`app()`/`dispatch()`).** The suite itself
plays the monolith: a `node:http` listener bound to `127.0.0.1`, verifying
Ed25519 signatures with `node:crypto`, started from a per-run generated
keypair (`scripts/dev-with-callback.mjs`, `npm run dev:callback` — never a
committed key). **13.** `app()` round trip, int64-exact across the boundary
matrix, every request signed with a fresh nonce and a fresh timestamp.
**14.** `app()` rejected inside a transaction: `ATOMS-E082`, and the listener
saw **no request at all** (the guest-side guard fires before crossing).
**15.** deadline overrun: 15a uncaught → 504 `turn_deadline_exceeded`,
residency stays healthy; 15b caught, then a second `app()` that fails
immediately on the latched budget with exactly one request ever reaching the
listener. **16.** `dispatch()` delivered, signed `X-Atoms-Kind: job`, args
keyed by promoted property name, delivered **before** the turn's HTTP
response is read. **17.** `dispatch()` transaction semantics: dropped on
rollback, delivered on commit, and delivered even when dispatched outside a
transaction followed by an uncaught throw (the documented asymmetry). **13–17
skip (not fail) when no callback listener is configured** —
`test/.callback-key.json` absent — so a run against a Worker with no callback
channel configured is still honest; 15 additionally skips when
`ATOMS_TURN_DEADLINE_MS` is not set in the runner's own environment.

**18–22 — WebSockets and `broadcast()`.** Node's built-in global `WebSocket`
(no `ws` dependency — `worker/package.json` is GPL-assembled, so every
dependency is a licensing question). **18.** connect, `onConnect` observed,
the full query string delivered as `params`, then a bad upgrade (too many
channels; a type with no WebSocket handler) refused before any DO work.
**19.** echo round trip through `onMessage` + `Connection::send()`, text and
binary. **20.** `broadcast()` reaches every connection on the channel and
only that channel, exact wire shape asserted, an empty channel is not an
error. **21. THE BIG ONE** — survival across a **real** hibernation: connect,
echo, wait the full (never-shortened) `ATOMS_EVICTION_WAIT_MS`, assert
`constructions` actually grew (otherwise the check fails rather than passing
vacuously on a warm residency), echo again on the **same** socket, same
connection id, `onConnect` did not re-run, tags survived, then close and
confirm `onDisconnect` fires post-wake. **22.** `send()` to a dead connection:
typed `ConnectionClosed`, scoped to the call — the sender's own socket keeps
working.

**23–24 — timers/alarms.** **23.** a timer fires and is consumed
(`scheduledAt()` returns `null` afterwards); a timer scheduled from inside
`onTimer` fires too (chain-1 → chain-2); a `schedule()` inside a rolled-back
transaction never fires; `cancel()` actually prevents the fire; a throwing
`onTimer` is still consumed at-most-once and the residency stays healthy;
`__atoms_timers` is reserved exactly like `__atoms_meta`; empty/over-long
names are `ATOMS-E085`. **24. THE HONEST ONE** — a Durable Object **alarm**
wakes an evicted Atom with no HTTP request involved: arm a timer due after the
full eviction wait, idle the unshortened `ATOMS_EVICTION_WAIT_MS`, assert
`constructions` grew (the same honesty gate as 12/21), then confirm the timer
fired exactly once via the alarm alone.

**21 and 24 both reuse the same unshortened `ATOMS_EVICTION_WAIT_MS`** check
12 uses — they are the hibernation-honesty gates for the WebSocket and timer
seams respectively, and shortening the wait for either would make them assert
nothing, for the same reason check 12 must not be shortened.

Remote-only additions: measure cold activation, warm turn, and
post-hibernation wake latencies; record them in `test/results/remote.json`.

## Appendix: measured deviations (2026-08-04, wrangler 4.118 local)

Two premises above are wrong about the platform as it actually behaves. They are
recorded here rather than worked around silently; the body of the spec is
otherwise unchanged and still binding.

1. **`ctx.storage.sql` speaks no BigInt, in either direction.** §"Int64 tagging"
   says "JS uses `BigInt` internally (`ctx.storage.sql` returns bigint for large
   ints)". Measured:
   - *Binding* a `bigint` throws `TypeError: Cannot convert a BigInt value to a
     number`. The host therefore folds any binding wider than 2^53−1 into the
     statement text as a validated decimal literal
     (`int64.js` → `inlineWideIntegers()`). Writes are exact.
   - *Reading* an INTEGER wider than 2^53−1 yields a **lossy double**
     (`SELECT 9223372036854775807` came back as `9223372036854775808`). The
     exact value is gone before the bridge is reached, so there is no host-side
     fix. The default is a typed `int64_precision` error, never a quietly wrong
     integer; `ATOMS_SQL_UNSAFE_INTEGER=tag` accepts the rounded value and logs
     a warning.

   Consequence for §"Fixture app" and conformance check 9: a wide integer must
   leave SQLite as text. `Vault::getBig()` selects `CAST(value AS TEXT)`, and
   the full ±(2^63−1) matrix round-trips exactly through
   args → SQL → results → `lastInsertId`.

   **Collateral restriction on REAL columns.** Because workerd hands INTEGER and
   REAL to JS identically, the host cannot tell a widened INTEGER from a REAL
   that happens to be integral — `Number.isInteger(1.0e30)` is true. The refusal
   above is therefore bounded as tightly as the platform allows, and no tighter:

   - magnitude > 2^63 — provably **not** a widened INTEGER (INT64_MAX rounds to
     exactly 2^63, INT64_MIN *is* −2^63), so it is a REAL, its double is exact,
     and it crosses as a plain JSON number. Large floats are readable.
   - magnitude in (2^53−1, 2^63] — genuinely ambiguous. `int64_precision` by
     default; there is no way to distinguish the cases, and quietly guessing is
     the one thing the bridge must not do. `ATOMS_SQL_UNSAFE_INTEGER` opens two
     documented escapes, both logging a warning: `tag` reads it as an integer
     (right for a wide INTEGER, wrong for a REAL) and `float` reads it as the
     double it is (right for a REAL, rounded for a wide INTEGER).

   A column holding large floating-point quantities in the ambiguous band should
   therefore either set `=float` or be selected through a cast the same way a
   wide integer is.

2. **`GLOB_BRACE` does not exist in the pinned php-wasm 8.3 build**, and brace
   patterns match nothing. The verbatim `Atoms\Migrations\MigrationSet::
   fromDirectory()` globs `*.{sql,php}`, so unshimmed it discovers zero
   migrations. `php/runtime/MigrationsGlobShim.php` shadows `glob()` and
   `GLOB_BRACE` inside the `Atoms\Migrations` namespace only; atoms-core is
   still verbatim and unpatched.

3. **The guest clock does not advance inside a turn on deployed workerd.**
   Cloudflare moves time forward only on I/O, and nothing the guest does inside
   one synchronous run counts: measured on the deployed Worker, `hrtime(true)`
   returned the *same* value across 6,000,000 PHP loop iterations **and** across
   a `sql.exec` host round trip (`Counter::clockProbe` → `spinNs: 0`,
   `sqlNs: 0`). Under `wrangler dev` the clock does advance, so this is a
   local-passes/remote-fails deviation.

   Consequence for §"Fixture app" and conformance check 11: a clock-driven
   busy-wait is not merely inaccurate in production, it never terminates. The
   original `while (hrtime(true) - $start < $target)` in
   `Counter::slowIncrement()` spun until the Durable Object hit its CPU limit
   and was reset ("Durable Object exceeded its CPU time limit and was reset",
   `cpuTime` 30000ms; the client saw a 38s hang then `internal`), at every
   `$delayMs` including 10. The fixture now bounds the spin by a work budget and
   keeps the clock test only as an early exit for environments where time moves.

   This is a property of the platform, not of the bridge, and it reaches
   customer code: `sleep()`, `usleep()`, and any elapsed-time measurement inside
   a turn are unusable on deployed workerd, and a turn that waits on the clock
   is a residency-killing hang rather than a slow turn. The host cannot defend
   against it — a turn is one synchronous guest run, so JS has no opportunity to
   preempt it. Nothing here weakens turn serialization: it is enforced by the
   DO's turn mutex, not by timing.

4. **M2 measurements (2026-08-12, pinned wrangler 4.118.0, workerd
   `1.20260730.1` local; deployed behaviour flagged separately below).**

   - **Ed25519 is present in workerd's WebCrypto, and interoperates with
     libsodium.** `crypto.subtle.importKey('pkcs8', …, 'Ed25519', false,
     ['sign'])` accepts the fixed 16-byte DER prefix `+` a raw 32-byte seed;
     the derived public key is byte-identical to `node:crypto`'s, and a
     signature workerd produces over the real signed-message shape verifies
     under `node:crypto.verify(null, …)` — the same primitive
     `sodium_crypto_sign_verify_detached` uses. Consequence: no
     `@noble/ed25519` fallback was needed, and none was added — the callback
     channel signs with platform crypto only (§The callback channel).
   - **A loopback `fetch()` from `wrangler dev`'s workerd to `127.0.0.1`
     works with no flag**, including with `HTTPS_PROXY` set in the
     environment (wrangler logs that it detected the proxy and used it for
     the request; the loopback call still went direct). This is what lets the
     conformance suite play the monolith itself (checks 13–17) while "tests
     never hit the network" stays literally true.
   - **`AbortSignal.timeout()` aborts an in-flight `fetch()` on schedule**,
     with a catchable `DOMException` named `TimeoutError`, and `Date.now()`
     measurably advances across the awaited, aborted fetch — the clock the
     turn-deadline budget depends on (§The turn deadline).
   - **An orphaned Durable Object `fetch()` still completes under LOCAL
     `wrangler dev`.** A promise handed to neither `await` nor `waitUntil()`
     was still delivered in every mode tested. **This must not be relied
     on** — deployed workerd is documented to cancel pending I/O once a
     response has been returned and nothing holds the request context, which
     is exactly the local-passes/remote-fails shape items 1–3 above already
     record. The design forbids orphaned callback/dispatch deliveries
     regardless of this measurement (§The callback channel, §Worker layout):
     every outbound request this Worker makes is started and awaited within
     the lifetime of the DO event that caused it. Deployed behaviour is
     otherwise unverified here.
   - **Hibernatable-socket limits, measured locally:** 10 tags per socket (an
     11th throws); a 256-character max tag length (a 257th throws); the
     attachment cap is 16384 bytes serialized. `ATOMS_WS_MAX_ATTACHMENT_BYTES`
     defaults to 512 anyway — far under the measured local limit — because
     production may enforce a smaller number (Cloudflare's published guidance
     has historically named one); that production figure is **unverified
     here**.
   - **`webSocketMessage` is gated by `blockConcurrencyWhile`, with delivery
     order preserved.** A frame sent while a gate was open was held until the
     gate closed, and a text frame sent before a binary frame was still
     delivered text-then-binary. This is why the WebSocket wake path
     (§AtomDurableObject lifecycle) needs no extra "is this residency warm
     yet?" check of its own.
   - **`ws.send()` inside `webSocketClose` does not throw — it is silently
     dropped.** The closing socket is still returned by `getWebSockets()`
     during that handler and gone by the next event. This is the platform
     half of the "accepted, not delivered" honesty caveat in §The WebSocket
     seam.
   - **`send()` after `close()` throws; a second `close()` does not.** Matches
     the dead-connection semantics documented in §The WebSocket seam:
     `ConnectionClosed` for a failed send, silent success for a redundant
     close.
   - **Local workerd fires Durable Object alarms, and a due alarm
     re-activates an evicted DO** — the mechanism §Timers depends on for
     "the alarm wakes an evicted residency with no HTTP request involved at
     all," and what conformance check 24 measures directly against a real
     eviction rather than assuming.

## Deployment (MVP)

`wrangler.jsonc` name `atoms-mvp-conformance`, `compatibility_date` current,
SQLite-backed DO class export + migration tag `v1`. Deploy with
`npx wrangler deploy`; the remote suite runs against the `workers.dev` URL
with `ATOMS_DEBUG_ENDPOINTS=1` and an `ATOMS_APP_KEY` secret set via
`wrangler secret`. Nothing here touches the legacy Fly path.
