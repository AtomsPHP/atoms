---
title: Jobs
description: Dispatch work from an Atom onto your application's queue.
---

A job is App-side code an Atom hands to your application's queue: a class
extending `Atoms\AtomJob` whose `handle()` method runs in the monolith, on
your queue infrastructure, with full framework access.

## Write one

The constructor parameters are the dispatch contract. They must be of the same
[allowed types as Methods signatures](/guides/methods/#allowed-parameter-and-return-types):

```php
namespace App\Atoms\GameRoom;

use Atoms\AtomJob;

class RecordGameResult extends AtomJob
{
    public function __construct(
        public readonly string $ref,
        public readonly int $seat,
    ) {
    }

    public function handle(): void
    {
        // runs on your queue, in your app
    }
}
```

Only promoted constructor properties can be used; other state does not make it across the wire.
`handle()` is resolved through your framework's container, so it can take
injected dependencies.

## Dispatch it

From Atom code, dispatch by class name with constructor arguments keyed by
parameter name:

```php
$this->dispatch(RecordGameResult::class, ['ref' => $ref, 'seat' => 1]);
```

By class name, never an instance: the job's code lives in your app, not on
the runtime, so it cannot be instantiated inside an Atom. The runtime sends the class
name and arguments over the [callback channel](/guides/callbacks/); your
adapter reconstructs the job and hands it to the framework's queue.

## Delivery guarantees

Delivery is tried once and is unordered. Outside a transaction it starts
immediately; inside `$this->db()->transaction()`, jobs are released only
after commit and dropped on rollback.

`dispatch()` returns `void`, so a delivery that later fails has no way to
reach your Atom code: the failure is logged on the Worker and the job is
dropped. Write jobs so that a lost delivery is acceptable and a repeated one
is harmless.
