---
name: atoms-testing
description: Test Atom code with AtomHarness, Laravel callers with Atoms::fake(), and Worker lifecycle behavior with atoms dev.
---

# Testing Atoms

## Test an Atom with AtomHarness

Configure Methods before the first invocation. The harness opens a temporary
SQLite database, applies migrations, and calls `onActivation()` when it boots.
`invoke()` boots it on first use. Call `boot()` directly to test activation.

```php
$harness = \Atoms\Testing\AtomHarness::for(ChatRoom::class, 'room-1')
    ->withMethods(new ChatRoom\Methods());

self::assertSame('HELLO', $harness->invoke('screenAndPost', ['hello']));
$harness->assertBroadcast('room');
```

The harness calls your Methods class in the same process. Supply test doubles
for its external dependencies. Use SQLite for queries and migrations, and pass
real payload objects so the serializer checks their types. The harness records
broadcasts and dispatched jobs. Use `$harness->assertDispatched(RecordResult::class)`
to check a job.

## Test a Laravel caller

Import `Atoms\Laravel\Facades\Atoms`. Keep the fake returned by `Atoms::fake()`.
Calling it again installs a fresh fake. A stub applies to every
ID of the given Atom type.

```php
$fake = Atoms::fake();
$fake->stub(GameRoom::class, 'ping', 'stubbed');

self::assertSame('stubbed', Atoms::get(GameRoom::class, 'g-1')->ping());
$fake->assertInvoked(GameRoom::class, 'ping');
self::assertSame('g-1', $fake->invocations()[0]['id']);
```

`assertInvoked()` can filter on method arguments. The Atom ID is in the
invocation record.

## Test the local Worker

Use `atoms dev --env staging` for Worker lifecycle and hibernation tests.
Choose an environment from `atoms.json`. The command uses the project's
installed Wrangler and requires no Cloudflare account.

<!-- atoms:generated -->
{{PROJECT_ATOMS}}
<!-- /atoms:generated -->
