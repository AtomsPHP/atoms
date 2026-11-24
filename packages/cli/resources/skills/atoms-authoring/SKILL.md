---
name: atoms-authoring
description: Write Atom classes, Methods, jobs, shared payloads, and SQLite migrations. Use the error catalog to fix build and boundary errors.
---

# Authoring Atoms

## Worker and application code

An `Atoms\Atom` subclass runs in the Worker's PHP 8.3 runtime. It can use
`atoms/core`, other Atom-side classes under the Atoms path, dependencies in
`atoms-composer.json`, and PHP functions available in the runtime. Keep models,
framework classes, facades, and framework helpers in the application.

`AtomMethods` and `AtomJob` subclasses run in the application. The build checks
their signatures. Call a Methods method with `$this->app()->getPlayer($id)`.
Dispatch a job by class name and use its constructor parameter names as keys.
Constructing the job inside the Atom raises `ATOMS-E104`.

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

Use `$this->db()` for SQLite queries, writes, and transactions. Use
`$this->config('PAYMENTS_API_KEY')` for Worker configuration. Lifecycle hooks
include `onActivation()` and `onDeactivation()`. WebSocket handlers are
`onConnect()`, `onMessage()`, and `onDisconnect()`.

## Boundary types

RPC arguments and results, Methods calls, jobs, and structured WebSocket
payloads use the same serializer. It accepts these types.

- `null`, booleans, integers, floats, and strings.
- Lists and string-keyed maps of supported values.
- Objects implementing `Atoms\Serialization\Payload`. Classes in `Shared/` run
  on both sides and must contain only data. The serializer matches public
  promoted properties by name.
- `\DateTimeImmutable` as an RFC 3339 string, and `\BackedEnum` as its backing value.

Keep closures, resources, mutable dates, and ORM models on their own side of
the boundary. To pass model data, copy the fields into a shared payload. Use
the Atoms serializer for boundary data.

## SQLite migrations

Give each SQL or PHP migration in `app/Atoms/{Atom}/migrations/` a unique
numeric prefix, as in `001_create_rooms.sql`. At activation, an Atom runs
versions above the one recorded in its database.

Add a higher-numbered migration for each change. Editing a shipped file can
give new Atoms a different schema from those that already ran it. The manifest
records file hashes, but validation does not compare releases or emit `ATOMS-E050`.
Keep the schema compatible with the previous code version because a code
rollback leaves the schema in place.

Keep migrations short. The first turn waits for them, and the build does not
check their duration or emit `ATOMS-E052`.

## Calls back to the application

An Atom waits for `$this->app()` to return before it can handle another turn.
A Methods call back into the same Atom ID would wait for the turn that called
it. Use `$this->dispatch()` for slow work that can run on the application queue.

## Error catalog

Look up the `ATOMS-E###` code from PHPStan or `atoms validate` for its fix.

<!-- atoms:generated -->
{{ERROR_CATALOG_TABLE}}
<!-- /atoms:generated -->
