---
title: Methods
description: Write the Methods class an Atom calls through app() to run code in your application.
---

A Methods class extends `Atoms\AtomMethods`. Its public methods run inside
your application, with full framework access, when the Atom calls
`$this->app()->method(...)`. Use it when the Atom needs something only your
application has, such as your models, mail, or other services.

:::caution
Using `app()` should be rare. Each call blocks the Atom — turns are serialized,
so nothing else runs on it until the round trip completes. You also lose the
benefit of offloading compute to Atoms.
:::

## Write one

`atoms make:atom GameRoom` scaffolds an empty Methods class next to the Atom.
Fill it with ordinary methods:

```php
namespace App\Atoms\GameRoom;

use Atoms\AtomMethods;

class Methods extends AtomMethods
{
    public function displayName(int $playerId): string
    {
        return User::findOrFail($playerId)->name;
    }
}
```

Every public method is callable from the Atom, and its signature is the
contract between the two sides.

## How it's found

When a callback arrives for an Atom type, the adapter resolves its Methods
class in this order:

1. an explicit mapping you configured in the adapter;
2. a registered class carrying `#[MethodsFor(GameRoom::class)]`;
3. the namespace convention: `App\Atoms\GameRoom` → `App\Atoms\GameRoom\Methods`.

If you follow the convention (which `make:atom` does), no registration is
needed. Use `#[MethodsFor]` when the Methods class lives elsewhere.

The class is instantiated through your framework's container when the adapter
has one, so constructor injection works; otherwise it is constructed directly
with no arguments.

## Allowed parameter and return types

Arguments and return values travel between the Atom and your application as
JSON, so parameters and return types are restricted to scalars,
arrays, `mixed`, `void`, nullables of those, classes implementing
`Atoms\Serialization\Payload`, `\DateTimeImmutable`, and backed enums.
`atoms/phpstan-rules` enforces this.

Return values are not turned back into objects inside the Atom.
A `\DateTimeImmutable` return value arrives in the Atom as its RFC 3339 string,
and a backed enum as its backing value. Scalars, arrays, and string-keyed maps
come back exactly as declared.

## Failures

A failed `app()` call throws in your Atom code, either because your
application returned an error or because the call ran past the time limit.
Catch the exception or let the turn fail. Either way the Atom stays healthy,
and database writes the turn made before the call are kept; a failed turn
doesn't undo them.

`app()` can't be called inside `$this->db()->transaction()` at all
(`ATOMS-E082`). Read what you need before opening the transaction.
