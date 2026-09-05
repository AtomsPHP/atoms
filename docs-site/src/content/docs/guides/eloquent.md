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
        "atoms/database-illuminate": "^0.5",
        "illuminate/database": "^12.0"
    }
}
```

`atoms build` resolves that file, ships the resolved tree in the bundle, and records it in the manifest. See [`atoms-composer.json`](/guides/configuration/#atoms-composerjson) for what the file accepts, and [deploy](/guides/deploy/) for how the vendor stage resolves, caches, and ships those packages.

## Boot the bridge inside a method

`EloquentBridge::boot()` takes the Atom's database and returns a connection you can call query builder methods on. It also registers that connection as Eloquent's default, so call it before touching a model.

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

## Differences from Eloquent in Laravel

The bridge runs against the Atom's own database, inside the runtime, so a few behaviors differ from the connection you'd get in a Laravel app.

### Transactions don't nest

An inner `transaction()` joins the transaction already open, the same way `$this->db()->transaction()` does. To roll back, throw from inside the closure. Don't call `rollBack()` by hand inside a nested `transaction()` — it discards every write since the outermost transaction began, and the enclosing wrappers then fail because the transaction is already closed. `afterCommit()` and `afterRollBack()` hooks throw, because the bridge installs no transactions manager.

### Schema changes go through Atoms migrations

The bridge is for queries and models, and it refuses Laravel's schema builder — `Schema::create()` and everything else that goes through `getSchemaBuilder()` throws. To change an Atom's schema, add a migration file to the Atom's `migrations/` directory; the runtime applies it the next time the Atom wakes up. See [lifecycle](/concepts/lifecycle/#migrations) for how migrations run.

### Model events don't fire

The bridge installs no event dispatcher, so `creating`, `saved`, and the other model events never fire, and observers never run. Timestamps, casts, and relationships work as usual.

### `getServerVersion()` doesn't reach the database

The runtime can't ask SQLite for its version, so the bridge reports a recent one by default. If you need it to specify a specific version, pass `['server_version' => '...']` as the second argument to the bridge's`boot()` method.

### Very large integers lose precision

Reading an integer column holds the same caveat as any other Atom read: values above 2^53-1 come back imprecise unless you read them as text. [Limits](/reference/limits/) covers the workaround.
