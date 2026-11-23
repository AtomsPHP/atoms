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

Inside an Atom, `$this->db()` exposes `pdo()`, `query()`, `execute()`, and
`transaction()`. Read configuration with `$this->config('PAYMENTS_API_KEY')`
and call app-side Methods with `$this->app()->getPlayer($id)`. Lifecycle hooks
include `onActivation()` and `onDeactivation()`. WebSocket handlers are
`onConnect()`, `onMessage()`, and `onDisconnect()`.

For example, if the app-side job `RecordGameResult` takes constructor arguments
`$ref` and `$seat`, the Atom can queue it and notify room subscribers.

```php
<?php

declare(strict_types=1);

namespace App\Atoms;

use App\Atoms\Jobs\RecordGameResult;
use Atoms\Atom;

final class GameRoom extends Atom
{
    public function recordResult(string $ref, int $seat): void
    {
        $this->dispatch(RecordGameResult::class, ['ref' => $ref, 'seat' => $seat]);
        $this->broadcast('room', ['ref' => $ref, 'seat' => $seat]);
    }
}
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

At activation, each Atom applies files in `app/Atoms/{Atom}/migrations/` whose
numeric prefix is higher than the version recorded in its SQLite database.

- Give each SQL or PHP migration a unique version, such as `001_create_rooms.sql`.
- Keep shipped files unchanged and append a higher-numbered migration for each
  change. If you edit a shipped file, Atoms that applied it skip the edit and
  new Atoms run the edited version. The manifest records each file's SHA-256.
  Validation does not compare hashes across releases or emit `ATOMS-E050` for an edit.
- Keep the schema compatible with the previous code version. Rolling back code
  leaves the database at its current schema version.
- Keep migrations short because the first turn waits for them. The build does
  not measure migration time or emit the `ATOMS-E052` budget warning.

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
