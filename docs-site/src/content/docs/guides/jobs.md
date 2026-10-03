---
title: Jobs
description: Dispatch work from an Atom onto your application's queue.
---

A job is work an Atom hands to your application's queue. It is a class
extending `Atoms\AtomJob` whose `handle()` method runs in your application,
on your queue workers, with full framework access.

## Write one

The constructor parameters are the arguments the Atom passes when it
dispatches the job. They are limited to the same
[types as Methods signatures](/guides/methods/#allowed-parameter-and-return-types):

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

Only promoted constructor properties reach the job; any other state is lost.
`handle()` is resolved through your framework's container, so it can take
injected dependencies.

## Dispatch it

From Atom code, dispatch by class name with constructor arguments keyed by
parameter name:

```php
$this->dispatch(RecordGameResult::class, ['ref' => $ref, 'seat' => 1]);
```

You pass the class name, not an instance, because the job's code lives in
your application and isn't available inside the Atom. The class name and
arguments travel over the [callback channel](/guides/callbacks/), and your
application rebuilds the job and pushes it onto its queue.

## Delivery guarantees

Each job is delivered to your application once, with no retry, and jobs
dispatched together can arrive in any order. Outside a transaction, delivery
starts immediately. Inside `$this->db()->transaction()`, jobs are sent only
after the commit and dropped on rollback.

`dispatch()` returns nothing, so your Atom never learns about a failed
delivery: the Worker logs it and drops the job. Write jobs so that a lost
delivery is acceptable and a repeated one is harmless.
