---
name: atoms-authoring
description: Write Atoms code in this repo — the two-worlds model, the serialization algebra, migration rules, reentrancy hazards, and the full ATOMS-E* error catalog with canonical fixes. Use when creating or editing anything under the Atoms path.
---

# Authoring Atoms

An Atom is a lightweight PHP class that runs on the Atoms platform runtime — a
framework-free PHP 8.3 process. It is **not** a Laravel/Symfony class. The single
rule everything else follows:

> **If it extends `Atom`, it leaves. If it extends `AtomMethods` or `AtomJob`, it
> stays. If it's in `Shared/`, it does both — so it must be pure data.**

## The two worlds

- **Atom-side — Atom classes** (`app/Atoms/GameRoom.php`) ship to the platform.
  Inside them you may reference only: `atoms/core` (the `Atoms\*` API), classes
  under the Atoms path (other Atoms, `Shared/` DTOs), packages declared in
  `atoms-composer.json`, and the PHP stdlib available in the runtime image.
  No `App\Models\*`, no framework classes, no facades, no global helpers.
- **App-side — Methods and AtomJob classes** (`GameRoom/Methods.php`,
  `Jobs/RecordGameResult.php`) stay in your monolith with full framework access.
  The Atom reaches the App side through `$this->app()->method(...)` and
  `$this->dispatch(SomeJob::class, ['param' => $value])`. Only their
  **signatures** are the contract the build validates — the code never ships.
  Which is exactly why a job is dispatched BY NAME: the class is not on the
  platform, so `dispatch()` takes the class NAME, never an instance — building
  a job with `new` inside an Atom is a build error (`ATOMS-E104`).
  `SomeJob::class` is a compile-time constant, so naming it neither loads nor
  ships anything.

What an Atom may touch on `atoms/core` (frozen API):

```php
$this->db();                       // Atoms\Database — pdo(), query(), execute(), transaction()
$this->app()->getPlayer($id);      // reverse RPC into your Methods class (App-side)
$this->dispatch(RecordGameResult::class, [  // hand a job to the monolith queue
    'ref' => $ref,                                // keys are the job's constructor
    'seat' => 1,                                  // parameter names
]);
$this->config('PAYMENTS_API_KEY');       // platform secrets/config — NOT env()
$this->broadcast('room', [...]);   // push to subscribers
// lifecycle: onActivation(), onDeactivation()
// websocket: onConnect(), onMessage(), onDisconnect()
```

## The serialization algebra (boundary types)

Everything crossing a boundary — RPC args/returns, `app()` calls, `dispatch()`
payloads, WebSocket frames, `Shared/` DTO properties — must be one of:

- `null`, `bool`, `int`, `float`, `string`
- lists and string-keyed maps of legal types
- classes implementing `Atoms\Serialization\Payload` (public promoted
  constructor properties, hydrated by name; nesting allowed)
- `\DateTimeImmutable` ⇄ RFC 3339 string
- `\BackedEnum` ⇄ its backed value

**Illegal** (build error + PHPStan error + runtime `SerializationException`):
closures, resources, `\DateTime` (mutable), Eloquent models / Doctrine entities,
anything container-bound. **Native `serialize()`/`unserialize()` never appears
anywhere.** To pass a model, map it to a Shared DTO
(`PlayerSnapshot::fromUser($user)`).

## Migrations (per-Atom SQLite)

Ordered `app/Atoms/{Atom}/migrations/NNN_*.sql` files run once, at activation,
under the Atom's single-writer guarantee. Rules the toolchain enforces:

- **Append-only after shipping.** Editing a shipped migration fails validation
  (the manifest records each file's sha256). Add a new `NNN_` file instead.
- **Strictly increasing** numeric prefixes (`001_`, `002_`, …); no gaps that
  reorder, no duplicates.
- **Backward-compatible one version** (expand/contract) — a code rollback does
  not roll back schema.
- Keep them tiny: they run in the activation path (budget ~250ms).

## Reentrancy hazards

Turns are single-threaded per Atom. Two traps:

- **A→B→A deadlock.** If Atom A calls `$this->app()` into a Methods class that
  calls back into Atom A (same id) before A's turn returns, the second call
  waits on a turn that cannot complete. Don't make a Methods class re-enter the
  Atom that called it.
- **`app()` head-of-line blocking.** `$this->app()` is a synchronous round-trip
  to the monolith; while it is in flight the Atom processes no other turn. Keep
  Methods calls fast; move slow work into an `AtomJob` via `$this->dispatch()`.

## Error catalog

Every build/boundary failure has a stable `ATOMS-E###` code. When PHPStan or
`atoms validate` prints one, find it here for the canonical fix and self-correct.

<!-- atoms:generated -->
{{ERROR_CATALOG_TABLE}}
<!-- /atoms:generated -->
