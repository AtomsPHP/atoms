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
conformance suite against `wrangler dev`; a real deploy + remote smoke.

Out of scope (MVP): WebSockets, alarms, `app()`/`dispatch()`/`broadcast()`
(they throw a typed `AtomsNotSupported` PDO-style exception), owned php-wasm
build, native `pdo_atoms`, the `atoms` CLI/action. These are explicit stubs,
never silent no-ops.

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

### Park ops (`'~'`)

- `{"op":"turn.await","result": <turn-result|null>}` — parks between turns.
  `result` reports the *previous* turn's outcome (null on first park after
  boot). Resumed with a turn envelope:
  `{"kind":"invoke","method":string,"args":[...]}` or `{"kind":"shutdown"}`.
- `{"op":"tx.begin"}` — host calls `ctx.storage.transactionSync(cb)` and
  resumes PHP **inside** `cb` with `{"ok":true}`.
- `{"op":"tx.commit"}` — parks so `cb` can return (committing); host resumes
  PHP after `transactionSync` returns with `{"ok":true,"committed":true}`.
- `{"op":"tx.rollback"}` — parks; host throws a sentinel inside `cb` so
  Cloudflare discards the write set, catches it outside, resumes with
  `{"ok":true,"rolledBack":true}`.

While a transaction is open, `sql.exec` runs inside the callback's scope on
the same connection (read-your-own-writes). The host rejects any park op other
than `tx.commit`/`tx.rollback` while a transaction is open, and rejects
`tx.begin` when one is already open (PHP's `Database::transaction()` already
guards nesting; the host guard is defense in depth).

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
`atom_not_found` (type absent from manifest), `internal` (runtime bug).
Traces are sanitized: never sent to the client, logged server-side only.

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

## Worker layout

Plain ESM JavaScript with JSDoc types (no build step beyond Wrangler; TS
migration is post-MVP). All operational values (timeouts, limits) come from
env vars with defaults resolved in one `config.js` module — no capacity
constants inline (workspace rule).

```
cloudflare/worker/
  src/
    index.js        # Worker entry: router, auth, error mapping
    atom-do.js      # AtomDurableObject class
    php-host.js     # boot + tagged-door dispatcher (ported from spike)
    bridge.js       # sql.exec/config.get/meta/log handlers, tx state machine
    int64.js        # tagging codec (JS side)
    config.js       # env-derived settings with defaults
  php/
    runtime/        # Atoms\Cf\* prelude: host_sync/host_park, bootstrap loop,
                    # BridgeDatabase, AtomsPDO, CfAtomContext, int64 codec,
                    # dispatcher, error envelope
    atoms-core/     # verbatim copies from packages/core/src (repo root):
                    # Atom.php, Runtime/{AtomContext,LifecycleInvoker}.php,
                    # Database.php, Migrations/*, Serialization/*, Websocket/*,
                    # AtomJob.php, AtomMethods.php (+ whatever they require)
  fixtures/
    counter/        # fixture "customer app": manifest.json + Atom classes
                    #   + migrations
  .php-wasm/        # php_8_3.asyncify.{js,wasm}  (pinned; staged by
                    #   scripts/prepare-runtime.mjs, gitignored, never committed)
  scripts/          # build-bundle.mjs (assemble fixtures into bundle.json)
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
  boot ms, user_version, memory high-water). Enabled only when
  `ATOMS_DEBUG_ENDPOINTS=1`.
- If the `ATOMS_APP_KEY` secret is set, every route except `/healthz`
  requires `Authorization: Bearer <key>`; if unset (local dev), auth is off.

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
- Hibernation needs nothing: each completed turn is durable;
  `onActivation()` runs once per residency by construction. There is no
  deactivation hook on eviction (best-effort by contract).

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

## Bundle format (MVP)

`scripts/build-bundle.mjs` walks a fixture app directory and emits
`src/bundle.generated.js`: `export default {manifest, files}` where `files`
maps guest paths (`/app/...`, `/atoms/...`) to file contents, and `manifest`
is `{"atoms":{"Counter":{"class":"App\\Atoms\\Counter","file":"/app/Counter.php",
"migrations":["/app/migrations/001_init.sql", ...]}}, "abi":{"php":"8.3"}}`.
The DO writes `files` into MEMFS at boot. No customer PHP executes at build
time. (The real `atoms build` integration is post-MVP; this format is
internal and versioned `bundle_format: 0`.)

## Fixture app (conformance subject)

`fixtures/counter/` defines two Atom types:

- `Counter` — `increment(int $by): int` (SQL update + returns new value),
  `getValue(): int`, `getStats(): array` (exercises Serializer arrays),
  in-memory `$turnsThisResidency` property (proves warm-residency),
  `onActivation()` writes an activation row (proves lifecycle),
  migration `001_init.sql` creating `counter_state`, migration
  `002_add_stats.sql` (proves ordered multi-migration).
- `Vault` — `putBig(string $key, int $value): void`, `getBig(string $key): int`
  (int64 boundary cases through args, SQL, and results),
  `transfer(...)` using `db()->transaction()` with a forced-failure path
  (proves genuine rollback), plus one method using `db()->pdo()` directly
  (proves AtomsPDO).

## Conformance suite

`test/conformance.mjs` runs against any base URL (`ATOMS_BASE_URL`), so the
same suite runs against `wrangler dev` and the deployed Worker:

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

## Deployment (MVP)

`wrangler.jsonc` name `atoms-mvp-conformance`, `compatibility_date` current,
SQLite-backed DO class export + migration tag `v1`. Deploy with
`npx wrangler deploy`; the remote suite runs against the `workers.dev` URL
with `ATOMS_DEBUG_ENDPOINTS=1` and an `ATOMS_APP_KEY` secret set via
`wrangler secret`. Nothing here touches the legacy Fly path.
