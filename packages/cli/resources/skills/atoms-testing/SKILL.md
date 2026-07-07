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

```php
$harness = AtomHarness::for(GameRoom::class, 'g-1');   // temp sqlite, migrations run
$harness->onActivation();

$result = $harness->call('startRound', [3]);           // invoke a public Atom method
self::assertSame(3, $result->round);

// Methods calls run the REAL Methods class in-process:
$harness->withMethods(new GameRoom\Methods(/* test doubles for World B deps */));

// dispatch()/broadcast() are recorded, not sent:
$harness->assertDispatched(RecordGameResult::class, fn ($j) => $j->score === 100);
$harness->assertBroadcast('room');
```

Turn semantics are simulated: in-process calls are sequential by construction, so
you exercise real turn ordering without a scheduler.

## Layer 2 — `Atoms::fake()` (monolith / controller tests)

For code that *calls* Atoms (controllers, jobs), swap the client with
`Atoms::fake()`: stub the return per `(type, id, method)` and assert invocations,
without any Atom actually running.

```php
Atoms::fake()->for(GameRoom::class, 'g-1')->returns('score', 42);
// ... hit the controller ...
Atoms::fake()->assertInvoked(GameRoom::class, 'g-1', 'score');
```

## Layer 3 — integration against `atoms local`

Use sparingly (WebSocket lifecycle, hibernation). Requires Docker; not for the
default CI path.

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
