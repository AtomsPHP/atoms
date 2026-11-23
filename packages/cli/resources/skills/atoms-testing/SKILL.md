---
name: atoms-testing
description: Test Atoms in this repo — AtomHarness for in-process Atom↔Methods coverage, Atoms::fake() for monolith controller tests, and what NOT to mock. Use when writing or reviewing tests for Atom code.
---

# Testing Atoms

Three layers, fastest first. Layers 1–2 run in plain PHPUnit with **no network
and no Docker** — keep it that way; the moment tests need infrastructure, CI
adoption stalls.

## Layer 1 — `AtomHarness` (unit, the workhorse)

`atoms/testing`'s `AtomHarness` instantiates an Atom in-process against a **temp
SQLite** database (migrations applied) and wires a **fake `app()` proxy that
executes your real Methods class in-process**. That gives full Atom↔Methods
integration coverage without a running platform.

In a PHPUnit test method, configure the harness before its first use.
`invoke()` boots it automatically, applying migrations and running the Atom's
activation hook. Call `boot()` explicitly to test activation on its own.

```php
$harness = \Atoms\Testing\AtomHarness::for(ChatRoom::class, 'room-1')
    ->withMethods(new ChatRoom\Methods());

self::assertSame('HELLO', $harness->invoke('screenAndPost', ['hello']));
$harness->assertBroadcast('room');

$harness->invoke('recordScore', [
    'alice', 10, new Score(10, 'bronze'), new \DateTimeImmutable('2026-02-02'),
]);
$harness->assertDispatched(RecordResult::class, static fn (RecordResult $job): bool => $job->points === 10);
```

Turn semantics are simulated: in-process calls are sequential by construction, so
you exercise real turn ordering without a scheduler.

## Layer 2 — `Atoms::fake()` (monolith / controller tests)

In Laravel tests for code that calls Atoms, import the `Atoms\Laravel\Facades\Atoms`
facade. Stubs match by Atom type and method. Call `Atoms::fake()` once per test
so the same fake holds the stubs and records the invocations.

```php
$fake = Atoms::fake();
$fake->stub(GameRoom::class, 'ping', 'stubbed');
self::assertSame('stubbed', Atoms::get(GameRoom::class, 'g-1')->ping());

$fake->assertInvoked(GameRoom::class, 'ping');
self::assertSame('g-1', $fake->invocations()[0]['id']);
```

`assertInvoked()` accepts an optional filter over method arguments. To match a
specific Atom ID, inspect `invocations()` as shown above.

## Layer 3 — local Worker integration

For lifecycle and hibernation tests, run `atoms dev --env X` with an environment
name from `atoms.json`. This starts the local Worker using Node and the project's
installed Wrangler. It requires no Cloudflare account.

## What NOT to mock

- **Don't mock `$this->db()`.** Use the harness's real temp SQLite — SQL bugs
  and migrations are exactly what you want covered.
- **Don't mock the Methods class in Layer 1.** The whole point is running it for
  real in-process; inject test doubles for *its* monolith dependencies instead.
- **Don't mock the serializer / the boundary.** Pass real Payload DTOs; letting
  the serializer run catches illegal boundary types (ATOMS-E02x) in tests.
- **Don't hit the network.** No real platform calls, ever, in Layers 1–2.

<!-- atoms:generated -->
{{PROJECT_ATOMS}}
<!-- /atoms:generated -->
