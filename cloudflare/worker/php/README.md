# `worker/php/` — everything that runs inside the PHP guest

Two directories, with very different rules:

| Directory | What it is | Rule |
|---|---|---|
| `atoms-core/` | Verbatim copies of `packages/core/src` (+ `resources/errors.json`), from the repository root | **Never edit.** See `atoms-core/VENDORED-FROM.md` |
| `runtime/` | The `Atoms\Cf\` prelude — the platform side of the guest | Owned here |

The whole point of the MVP is that the first column runs *unmodified*: the
customer ABI, `Atoms\Migrations\Migrator` and `Atoms\Serialization\Serializer`
inside a Durable Object are the same code the `atoms/core` package ships to
customers. Nothing in `atoms-core/` was patched to make that work.

---

## What the JS host must do

### 1. Write these files into MEMFS

Guest paths are load-bearing in two places, so they are pinned rather than
free-form:

```
/atoms/core/src/…            <- worker/php/atoms-core/*.php  (same tree)
/atoms/core/resources/errors.json
                             <- worker/php/atoms-core/resources/errors.json
/atoms/runtime/*.php         <- worker/php/runtime/*.php     (flat)
/app/…                       <- the bundle's files, at the paths its manifest uses
```

- `Atoms\Errors\ErrorCatalog` resolves its catalog as
  `__DIR__ . '/../../resources/errors.json'`. That is why the core sources sit
  under `…/core/src/` with `errors.json` as their sibling `…/core/resources/` —
  it is the only layout in which the verbatim file finds its data.
- `Atoms\Migrations\MigrationSet::fromDirectory()` *globs* a directory, so an
  Atom type's migration files must all be written into one directory, and only
  its own migrations may live there.

`$CFG.paths` can move the first two roots (see below); `/app/…` comes from the
manifest and is never assumed.

### 2. Run exactly one composed script

`php.run()` is a request: globals, classes and the PHP stack are torn down when
it returns, so an Atom with in-memory state cannot be one `run()` per turn.
There is **one** `php.run()` per residency, and it does not return until the
host sends `{"kind":"shutdown"}`. Its `code` is exactly this:

```php
<?php
$CFG = json_decode(<<<'ATOMSBOOTJSON'
{"protocol":1,"atom":{"type":"Counter","id":"…"},"manifest":{…},"files":[…],"paths":{…}}
ATOMSBOOTJSON, true);
$ATOMS_BOOT = $CFG;
require '/atoms/runtime/bootstrap.php';
```

(`src/php-host.js` → `composeBootCode()`. `$ATOMS_BOOT` is an alias of the same
array, kept so either name works; `bootstrap.php` reads `$CFG`.)

Rules for the composed script:

- **Encode `$CFG` as single-line JSON inside a nowdoc.** Nowdoc means no
  interpolation and no escaping problems, and `JSON.stringify` never emits a
  raw newline, so the closing identifier can never collide with the payload.
- **`require`, never concatenate.** Every file in `atoms-core/` begins with
  `declare(strict_types=1)`, which must be the first statement *of its file*.
  Concatenating them is the exact fatal the pre-MVP spike hit. Nothing under
  `runtime/` declares
  `strict_types`, precisely so the composed entry stays safe to build.
- Do not add `declare(strict_types=1)` to the entry script either.

### 3. `$CFG` contract

```jsonc
{
  "protocol": 1,                                           // boot-payload version
  "bundle_format": 0,
  "host": "cloudflare-do",
  "atom":     { "type": "Counter", "id": "counter-1" },   // required, both strings
  "manifest": {                                            // the bundle manifest
    "bundle_format": 0,
    "abi": { "php": "8.3" },
    "atoms": {
      "Counter": {
        "class": "App\\Atoms\\Counter",                    // required
        "file":  "/app/Counter.php",                       // required, guest path
        "migrations": ["/app/migrations/001_init.sql"]     // optional, ordered
      }
    }
  },
  "files":    ["/app/Counter.php", "/app/Stats.php"],      // optional; guest paths
                                                           // the host wrote
  "paths":    { "core": "/atoms/core/src",                 // optional; these are
                "runtime": "/atoms/runtime",               // the defaults
                "bootstrap": "/atoms/runtime/bootstrap.php",
                "boot_payload": "/atoms/boot.json" },
  "residency": { "constructions": 1, "do_id": "…" },       // host bookkeeping,
  "debug": true                                            // informational only
}
```

The same payload is also written to `paths.boot_payload` (`/atoms/boot.json`),
so the prelude can read it from disk instead of the global. Every path above is
overridable from the Worker's env (`ATOMS_CORE_DIR`, `ATOMS_RUNTIME_DIR`,
`ATOMS_BOOTSTRAP_PATH`, `ATOMS_BOOT_PAYLOAD_PATH`) — see `src/config.js`.

`files` is what the bundle-class autoloader indexes, so an Atom can reference
Payload DTOs and helper classes in sibling files. Migration paths are excluded
from it automatically — a `NNN_name.php` migration *returns* a `Migration`
object and must only ever be loaded by `MigrationEntry::migration()`. If `files`
is omitted, only the Atom's own `file` is loaded.

`manifest.vendor.autoload` (optional, mvp-spec.md §Bundle format) names the
guest path of a build-generated classmap + function-file loader for a bundled
vendor tree. When present, everything under that file's own directory is
excluded from the line-scanning autoloader's index — the classmap is exact,
and reading a vendor tree's every file at each activation would be pure boot
cost — and the file is `require`d right after the bundle autoloader is
registered. Declared but missing from the guest filesystem is an `internal`
`BootstrapError`, like a missing Atom source.

### 4. What happens next, in order

`bootstrap.php` requires `host.php` and `int64.php`, then:

1. `require`s `atoms-core/` (interfaces first) and then the rest of `runtime/`;
2. registers the bundle autoloader (vendor subtree excluded when the manifest
   declares `vendor.autoload`), then `require`s the declared vendor autoload
   file, if any;
3. applies pending migrations with the real `Migrator` — each migration in its
   own transaction (`tx.begin`/`tx.commit`), with `PRAGMA user_version` read and
   written through the bridge for the host to map onto `__atoms_meta`;
4. `require`s the Atom's file, checks the class extends `Atoms\Atom`, constructs
   `new $class($id, $context)`;
5. calls `LifecycleInvoker::activate()` (the Atom's `onActivation()` hook);
6. emits one `log` line `{"event":"activated", …}`;
7. parks at `turn.await` with `result: null` and loops.

If any of steps 1–6 fails, the guest emits
`{"event":"activation_failed","code":"atom_not_found"|"internal", …}` on the
`log` door **with the trace**, and then rethrows — `php.run()` unwinds and the
residency is poisoned, which is the spec's prescribed handling. There is no
turn-result envelope at that point, so the log line is the host's only signal.

### 5. The turn loop

Each iteration parks on `{"op":"turn.await","result":<previous envelope|null>}`
and expects the host to resume it with the turn envelope's fields **alongside
`ok: true`**:

```json
{"ok": true, "kind": "invoke", "method": "increment", "args": [1]}
{"ok": true, "kind": "shutdown"}
{"ok": true, "kind": "ws.connect", "conn": {"id": "…", "channels": ["lobby"]}, "params": {"channels": "lobby"}}
{"ok": true, "kind": "ws.message", "conn": {…}, "payload": "hi", "binary": false, "encoding": "utf8"}
{"ok": true, "kind": "ws.close", "conn": {…}, "code": 1000, "reason": "", "wasClean": true}
{"ok": true, "kind": "timer", "name": "…"}
```

The result carried into the *next* park is one of:

```json
{"ok": true,  "result": <Serializer-normalized, int64-tagged>}
{"ok": false, "error": {"code": "…", "message": "…", "class": "FQCN"|null}}
```

`code` is `atom_exception` (the customer method threw, including a boundary
type mismatch on its args or return), `method_not_found` (no such public
customer method — `class` is then `null`), `turn_deadline_exceeded` (an
uncaught `app()` deadline overrun — `invoke` turns only), or `internal` (a
runtime bug, or a turn envelope the guest could not understand). Traces never
appear in an envelope; they go out on the `log` door.

`ws.*`/`timer` turns dispatch through `run_ws_turn()`/`run_timer_turn()`
(`bootstrap.php`) rather than `run_turn()`: no `invocable_method()`, no
`Serializer::denormalizeArguments()` (the arguments are runtime-constructed,
nothing to coerce), and the success envelope is always
`{"ok": true, "result": null}` — the handlers return `void`. An uncaught
exception in one is logged and becomes `atom_exception`, same as an invoke,
but is **never** relayed to the socket peer in any form (`mvp-spec.md`
§Turn-result envelope).

---

## `runtime/` file map

Load order matters and is encoded in `bootstrap.php`; this is what each file is.

| File | Role |
|---|---|
| `host.php` | The four doors: `host_sync()`/`host_park()` over `post_message_to_js` with `'!'`/`'~'`, plus `host_sync_raw()`/`host_park_raw()` (raw-reply variants used where the caller maps `error.code` onto a typed exception itself — SQL, and the callback channel) and best-effort `host_log()` |
| `int64.php` | `{"$atoms_int64":"<decimal>"}` ⇄ native int, recursive, refusing anything lossy |
| `BootstrapError.php` | Activation-path failure carrying a spec error code |
| `MigrationsGlobShim.php` | Host shim: this php-wasm build has no `GLOB_BRACE`, so the verbatim `MigrationSet::fromDirectory()` would find **zero** migrations. Shadows `glob()`/`GLOB_BRACE` inside `Atoms\Migrations` only |
| `AtomsNotSupported.php` | The permanently-unsupported PDO surface's one honest failure mode (`extends \PDOException`) — see §Documented leaks below |
| `FetchMode.php` | The PDO fetch modes the shim serves, and the row reshaping |
| `NamedParams.php` | `:name` → `?` rewriting, in PHP, skipping literals and comments |
| `SqlBridge.php` | Sole owner of `sql.exec` and the `tx.*` ops, and of the one shared transaction flag `CfAtomContext`'s `app()` guard also reads |
| `AtomsStatement.php` | `\PDOStatement` subclass; routes to the bridge or throws |
| `AtomsPDO.php` | `\PDO` subclass; same rule, plus SQLite-correct `quote()` |
| `BridgeDatabase.php` | `Atoms\Database`: `query`/`execute`/`transaction`/`pdo` |
| `CallbackError.php` | Base `\RuntimeException` for the callback channel's typed failures, formatted through `ErrorCatalog::format()` |
| `CallbackNotConfigured.php` / `CallbackUnsigned.php` / `CallbackInTransaction.php` / `CallbackFailed.php` / `JobNotEncodable.php` | ATOMS-E080–E084 — see `mvp-spec.md` §The callback channel |
| `TurnDeadlineExceeded.php` | Reuses ATOMS-E061; thrown by `CallbackAppProxy` on an `app()` deadline overrun |
| `CallbackChannel.php` | The one place that maps a host door failure onto the typed exception above, shared by `app()` and `dispatch()` |
| `CallbackAppProxy.php` | `app()`'s `__call()` proxy: the transaction guard, body encoding, the `app.call` park, result decoding |
| `ConnectionClosed.php` | Thrown by `CfConnection::send()` on `ws_conn_gone` — not a catalog code (see below) |
| `CfConnection.php` | `Atoms\Websocket\Connection`: a connection id string and nothing else, `send()`/`close()` over `ws.send`/`ws.close`; `sendJson()` encodes through `Atoms\Websocket\JsonFrame` and delegates to `send()`, inheriting its UTF-8 rule, size cap and `ConnectionClosed` |
| `CfMessage.php` | `Atoms\Websocket\Message`: the decoded bytes + `isBinary()` of one inbound frame; `json()` decodes them through `JsonFrame`, throwing `\JsonException` for malformed input and for a top-level non-object |
| `InvalidTimerName.php` / `TimerLimitExceeded.php` | ATOMS-E085 / ATOMS-E086 |
| `CfTimers.php` | `Atoms\Timers\Timers`: `schedule()`/`cancel()`/`scheduledAt()` over `timer.schedule`/`timer.cancel`/`timer.get` |
| `CfAtomContext.php` | `Atoms\Runtime\AtomContext`: `db()`, `config()`, `app()`, `dispatch()`, `broadcast()`, `timers()` — all real as of M2 |
| `bootstrap.php` | Activation + the parked turn loop (`run_turn()`/`run_ws_turn()`/`run_timer_turn()`); the only file the host requires by name |

### Documented leaks and limits

**The PDO shim (`db()->pdo()`).** Recorded here rather than hidden, because it
is a hand-written subclass with no driver behind it — this is the one corner
of the runtime surface that stays a permanent, typed restriction rather than a
milestone stub. It used to be audited by hand; as of M1 it is audited by a
reflection tripwire (conformance check 26) against the runtime `\PDO`/
`\PDOStatement`, and measured member-by-member against a native in-guest
`pdo_sqlite` by a differential harness (checks 27-28) whose result is
published, generated, and drift-checked: **the full, member-by-member matrix
is `cloudflare/docs/pdo-compatibility.md` (check 30) — prefer it to any prose
list, including this one.** What follows is the short list of what stays
permanently refused, not the whole surface:

- `getColumnMeta()`, `nextRowset()`, `PDOStatement::getAttribute()`/
  `setAttribute()` — no driver-owned statement handle exists to answer any of
  these from (the latter three refuse with the same `SQLSTATE[IM001]` real
  pdo_sqlite itself answers, so they are a genuine `refused_by_both`, not a gap).
- Scrollable cursors (`FETCH_ORI_*` other than the default `FETCH_ORI_NEXT`,
  `ATTR_CURSOR => CURSOR_SCROLL`) — real pdo_sqlite has none either (measured:
  it silently *ignores* the orientation on a forward-only cursor rather than
  refusing it); this shim refuses loudly instead of reproducing that silent
  wrong row.
- `PARAM_LOB` — binary values do not cross the JSON bridge. Store them
  base64-encoded as text.
- `PDO::prepare()` driver options other than `[]` or
  `[ATTR_CURSOR => CURSOR_FWDONLY]` (including `ATTR_STATEMENT_CLASS` and
  `ATTR_TIMEOUT`) — real pdo_sqlite silently ignores or silently refuses these;
  silently answering either way is the exact failure mode this shim exists to
  avoid, so it refuses instead.
- `sqliteCreateFunction()`/`sqliteCreateAggregate()`/`sqliteCreateCollation()`
  — statements execute inside the Durable Object; a guest callback cannot be
  registered with them.
- `getAttribute()`/`setAttribute()` on the two version attributes
  (`ATTR_SERVER_VERSION`, `ATTR_CLIENT_VERSION`) — the guest's SQLite and the
  Durable Object's SQLite are different builds, so any single answer would
  misrepresent one of them; there is also no client library on this side of
  the wire.
- `FETCH_LAZY` — returns a `PDORow`, an internal class bound to a live
  statement and unconstructible from userland.
- `FETCH_NAMED`, and positional fetch (`FETCH_NUM`/`FETCH_BOTH`/
  `FETCH_COLUMN` by index) over a result set with **duplicate column names** —
  the wire's `{column: value}` row maps have already collapsed duplicates
  (last value wins) before this shim ever sees the row, so the values a
  correct answer would need are gone. `FETCH_ASSOC`/`FETCH_OBJ` are unaffected
  (they collapse identically on both sides) and `columnCount()` is exact even
  under duplicates, because the column *names* (with duplicates preserved)
  survive separately, out of band from the row data.

Everything else that was on this list before M1 — `bindParam()`,
`bindColumn()`/`FETCH_BOUND`, `fetchObject()`, `FETCH_CLASS`/`FETCH_INTO`/
`FETCH_FUNC`/`FETCH_GROUP`/`FETCH_UNIQUE`, `debugDumpParams()`,
`execute([])` keeping previously-bound values, `lastInsertId($name)`,
`fetchColumn()` out-of-range, and typed bind coercion (`PARAM_INT`/`BOOL`/
`NULL`/`STR`) — is now filled and measured, not thrown. See the generated
matrix for exactly what each one does.

Other differences worth knowing, not omissions:

- `PDOStatement::$queryString` is asserted **set** by the reflection
  tripwire's allowlist (entry A1): the constructor's own first write, to a
  property PHP has never seen written before, always succeeds (there are no
  property hooks in 8.3 to intercept it). Measured on THIS 8.3 build: a
  *second* write from outside the class — after the property already holds a
  value — is refused, matching a real driver statement's read-only property
  exactly (`stmt.queryString.is_writable` observes `match`, not the deviation
  an 8.4 measurement predicted; see the differential matrix).
- Statement error state is scoped to the statement that failed, not the
  connection: after a statement's `execute()` fails, that statement's
  `errorCode()`/`errorInfo()` report the failure and the connection's own
  triple is left UNCHANGED — matching real PDO, which does the same in both
  directions (M1 review round 2, R4, measured): a statement's `execute()`,
  whether it fails OR succeeds, never touches the connection's triple.
  `AtomsPDO`'s own triple changes only through its own direct operations —
  `exec()`/`query()`/`lastInsertId()`/`prepare()`/`quote()`/`getAttribute()`
  reset it to clean on success (and set it on failure, where applicable);
  `beginTransaction()`/`commit()`/`rollBack()` set it on failure but do
  **not** reset it on success, so a stale error can survive a clean
  begin/commit/rollback cycle.
- `PDOException::getCode()` carries the SQLSTATE string (e.g. `'23000'`),
  matching real PDO's own exceptions — not the `0` a plain `\PDOException`
  defaults to.
- `execute($array)` binds each value with its own native PHP type (int stays
  an int, etc.), not PDO's own "stringify everything passed to `execute()`"
  behaviour. Deliberate: replicating PDO's stringification would push wide
  integers through as TEXT literals and put conformance check 9's int64
  exactness at the mercy of column affinity. Pinned as a deviation; bind
  explicitly with `bindValue()` for exact PDO-typed coercion.
- `getAttribute()` answers `ATTR_DRIVER_NAME`, `ATTR_ERRMODE`,
  `ATTR_DEFAULT_FETCH_MODE`, `ATTR_PERSISTENT`, `ATTR_CASE` and
  `ATTR_ORACLE_NULLS` truthfully; `setAttribute()` on the latter two accepts
  only their natural values (case folding and NULL/empty-string reshaping on
  fetch are not implemented) and refuses the rest.
- **Result-set caps.** `ATOMS_SQL_MAX_ROWS` and `ATOMS_SQL_MAX_RESULT_BYTES`
  bound a single `sql.exec` in rows mode; either one firing raises
  `sql_result_too_large`, which reaches PHP as a `\PDOException` exactly like
  any other SQL failure. `run` mode (no buffered rows) is unaffected.
- `PDOStatement::rowCount()` reports `rows_written`, i.e. PDO's documented
  meaning (affected rows), not the size of a SELECT's result set.
- **Parameter/return typing across the shim, in general.** `AtomsPDO::quote()`
  was fixed (M1 review F-5) to declare the SAME parameter types real
  `\PDO::quote()` does (`string $string, int $type = PARAM_STR`), because a
  looser signature there let a call that real PDO refuses at the argument
  boundary (`declare(strict_types=1)`, a non-string `$string`) fall through
  silently on our side instead. That was fixed as a targeted case, not as a
  blanket audit: other members of `AtomsPDO`/`AtomsStatement` may still keep
  looser parameter or return types than the parent they subclass, so under a
  `strict_types` call site a `\TypeError` real PDO would raise may not be
  raised by Atoms for a member the differential matrix has not yet exercised
  with a type-boundary-violating argument. Reviewed and accepted for M1: the
  harness's `Cases.php` itself runs under `declare(strict_types=1)`, so this
  class of gap surfaces (and gets fixed, member by member, the way `quote()`
  just was) as the matrix's cases come to exercise it, rather than needing a
  separate audit pass. **This declines the WEAK-typing axis almost entirely**
  — `Cases.php`'s own `strict_types=1` means a weak-mode call boundary is
  never exercised there, and that declining opened one measured, real
  residue: under weak typing, `quote(null)` on a real (internal) `\PDO`
  still coerces `null` to the empty string (PHP's legacy leniency for
  internal functions, deprecated as of 8.1 but not an error), while our
  `quote()` — a userland method — never had that leniency and throws a
  `\TypeError` regardless of the caller's `strict_types` setting. See the
  differential matrix's dedicated `Weak-mode type boundaries` group
  (`fixtures/counter/app/Pdo/CasesWeak.php`, the one case file in the whole
  matrix WITHOUT `declare(strict_types=1)`) — `pdo.quote.weak_null` is
  pinned `refused_by_us` for exactly this gap.
- An INTEGER wider than 2^53−1 cannot be **read back** from Durable Object SQL:
  workerd hands it to JS as a double, so the exact value is gone before the
  bridge sees it. The host answers with a typed `int64_precision` error rather
  than a quietly wrong integer, which reaches PHP as a `\PDOException`. Select
  such a column as `CAST(col AS TEXT)` (the fixture `Vault` does) — writing them
  is exact, only reading needs the cast. See the spec appendix.

**The callback channel (`app()`/`dispatch()`, M2).**

- `app()->foo()` returns the decoded wire tree — scalars, lists,
  string-keyed maps — **not** hydrated back into `Payload` DTOs,
  `\DateTimeImmutable` or `\BackedEnum` return values. A documented gap, not a
  bug; see `mvp-spec.md` §The callback channel's result-hydration gap.
- `dispatch()` is **at-most-once, unordered, and unretried**: a delivery
  failure (transport error, timeout, non-2xx) is logged and dropped, silently
  from the customer's point of view, because `dispatch(): void` cannot report
  one without becoming a blocking call. Initiation failures (bad channel
  config, an unencodable job) are the opposite — loud, thrown from
  `dispatch()` itself.
- `manifest_hash` is omitted from the `methods` request body in M2 — see
  `docs/conventions.md` §Callback signing.

**WebSockets and `broadcast()` (M2).**

- **A frame sent inside a transaction that later rolls back has already gone
  out.** `$conn->send()`/`close()`/`broadcast()` are sync ops, not gated by
  transaction state — buffering them to commit would change `send()`'s timing
  semantics for every caller to guard a case the customer can avoid by moving
  the send after the commit.
- **`send()` returning normally means accepted for delivery, never
  delivered.** The host can only detect "gone" when a connection id resolves
  to no socket at all; a socket mid-teardown accepts a `ws.send()` and the
  platform silently drops the frame. The absence of `ConnectionClosed` is not
  a delivery guarantee.
- **Channel membership is immutable after connect.** There is no
  subscribe/unsubscribe — reconnecting is the only way to change channels
  (the frozen `Connection` interface gained no method).
- `ConnectionClosed` is a plain `\RuntimeException`, not a catalog entry — the
  `Atoms\Cf` prelude has never carried `ATOMS-E###` codes, and neither does
  `AtomsNotSupported`.

**Timers (M2).**

- `dispatch()` from inside `onTimer()` and `broadcast()` from a timer turn
  both work identically to an invoke — a timer turn is an ordinary turn
  (same deadline budget, same `app()`/`dispatch()` availability).
- Firing is **at-most-once**: the due row is deleted *before* the timer turn
  dispatches, so a throwing (or residency-poisoning) `onTimer` still consumes
  the timer rather than being retried by a later alarm. This is deliberately
  not an at-least-once queue.
