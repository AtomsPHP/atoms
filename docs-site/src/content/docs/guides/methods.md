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

Include `--with-methods` when scaffolding the Atom:

```sh
vendor/bin/atoms make:atom GameRoom --with-methods
```

In Laravel, the equivalent Artisan command is:

```sh
php artisan make:atom GameRoom --with-methods
```

With the default `app/Atoms` path and the `App\` → `app/` Composer mapping,
either command creates `app/Atoms/GameRoom.php` and
`app/Atoms/GameRoom/Methods.php`. The Methods class uses the
`App\Atoms\GameRoom` namespace. Without `--with-methods`, the generator creates
only the Atom class.

Fill `app/Atoms/GameRoom/Methods.php` with ordinary methods:

```php title="app/Atoms/GameRoom/Methods.php"
<?php

declare(strict_types=1);

namespace App\Atoms\GameRoom;

use Atoms\AtomMethods;

class Methods extends AtomMethods
{
    public function appLabel(): string
    {
        return 'Laravel';
    }
}
```

Every public method is callable from the Atom, and its signature is the
contract between the two sides. Call it from the Atom:

```php title="app/Atoms/GameRoom.php"
<?php

declare(strict_types=1);

namespace App\Atoms;

use Atoms\Atom;

/** @extends Atom<\App\Atoms\GameRoom\Methods> */
class GameRoom extends Atom
{
    public function appLabel(): string
    {
        return $this->app()->appLabel();
    }
}
```

The `@extends` annotation gives PHPStan and your editor the concrete Methods
type returned by `$this->app()`. `make:atom --with-methods` generates it using
your project's namespace. Add it to existing Atoms when adding callbacks, and
name the actual Methods class if you use a custom mapping. The annotation
neither ships the Methods class to the Worker nor changes callback discovery.

Include the Atoms rules in your application's PHPStan configuration:

```yaml title="phpstan.neon"
includes:
    - vendor/atoms/phpstan-rules/rules.neon

parameters:
    level: 5
    paths:
        - app/Atoms
```

Run `vendor/bin/phpstan analyse` with the application's Composer autoloader
available. This example passes analysis; misspelling the callback as
`$this->app()->appLabl()` reports an undefined method on
`App\Atoms\GameRoom\Methods`.

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
([ATOMS-E082](/reference/errors/#atoms-e082)). Read what you need before opening the transaction.
