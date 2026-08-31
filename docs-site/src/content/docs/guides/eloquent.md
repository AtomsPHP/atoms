---
title: Eloquent and the query builder
description: Run the Laravel query builder and Eloquent models against an Atom's own SQLite database with atoms/database-illuminate.
---

`atoms/database-illuminate` boots `illuminate/database` against the SQLite database an Atom already owns, so an Atom can use the Laravel query builder and Eloquent models over its durable state.

## Install it as an Atom-side dependency

The bridge runs inside the Atom, which means it belongs in `atoms-composer.json` alongside `illuminate/database`, not in your application's `composer.json`:

```json
{
    "require": {
        "atoms/database-illuminate": "^0.4",
        "illuminate/database": "^12.0"
    }
}
```

`atoms build` resolves that file, ships the resolved tree in the bundle, and records it in the manifest. See [deploy](/guides/deploy/) for how the vendor stage resolves, caches, and ships those packages.

## Boot the bridge inside a method

`EloquentBridge::boot()` takes the Atom's own `Database` and returns a connection registered as Eloquent's default. It caches per `Database` instance, so the first turn of a residency pays for the boot and later turns reuse it:

```php
namespace App\Atoms;

use Atoms\Atom;
use Atoms\DatabaseIlluminate\EloquentBridge;
use App\Atoms\GameRoom\Support\Score;

class GameRoom extends Atom
{
    public function record(string $player, int $score): array
    {
        $db = EloquentBridge::boot($this->db());

        $db->table('scores')->insert(['player' => $player, 'score' => $score]);

        return Score::query()
            ->orderByDesc('score')
            ->limit(10)
            ->get()
            ->toArray();
    }
}
```

Models are Atom-side classes: keep them in the Atom's `support/` directory, where they ship with the Atom and follow the same import rules as the Atom class itself.

Return array shapes from Atom methods. An Eloquent model carries a connection and behavior, so it is not a value that crosses the boundary — call `->toArray()`, or map to a [shared DTO](/concepts/two-worlds/), before returning.

## Three deliberate differences

The bridge inherits the semantics of the runtime the Atom executes in, so three behaviors differ from a stock Laravel connection.

**Nested transactions reuse the outer transaction.** The runtime has no savepoints, so an inner `transaction()` joins the one already open — the same semantics as `$this->db()->transaction()`. Roll back by throwing. A hand-called `rollBack()` inside a nested `transaction()` discards the entire write set, and the enclosing wrappers then fail loudly on the closed transaction. `afterCommit()` and `afterRollBack()` hooks throw, because the bridge installs no transactions manager.

**Schema work is refused with `ATOMS-E106`.** Atoms migrations own DDL: ship schema changes as append-only files under the Atom's `migrations/` directory, where they run at activation and are tracked in `PRAGMA user_version`. The bridge serves queries and models.

**`getServerVersion()` answers from configuration.** The runtime cannot ask the engine, so the value comes from the `server_version` connection config key, which `EloquentBridge::boot()` accepts as its second argument.

Integer columns hold the same wide-integer caveat as any other Atom read; [limits](/reference/limits/) covers the read shape that keeps values above 2^53-1 exact.
