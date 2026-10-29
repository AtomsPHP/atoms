---
title: Lifecycle and persistence
description: How Atom instances are addressed, handle one turn at a time, apply migrations, and survive hibernation.
---

## Identity

An Atom instance is identified by its class and an ID you choose. From your application, you address it with both, through the Laravel `Atoms` facade or an injected `AtomsClient`:

```php
$count = Atoms::get(GameRoom::class, 'room-42')->join('ada');
```

Every call with the same class and ID reaches the same instance and the same SQLite database. Calling an ID for the first time creates the instance. Inside the Atom, the ID is available as `$this->id`.

## A turn at a time

Each invocation from your application, WebSocket event, or [timer](/guides/websockets-timers/#timers) is a turn. An instance runs one turn at a time, so two requests to `Document-123` never change its state concurrently.

## Transactions

Use SQLite transactions when several writes must succeed or roll back together:

```php
return $this->db()->transaction(function (): int {
    $this->db()->execute('UPDATE inventory SET stock = stock - 1 WHERE sku = ?', ['book']);
    $this->db()->execute('INSERT INTO orders (sku) VALUES (?)', ['book']);

    return (int) $this->db()->lastInsertId();
});
```

### Transaction behavior

- `app()` can't be called inside a transaction ([ATOMS-E082](/reference/errors/#atoms-e082)). Read what you need from your application before opening the transaction, or close it and open a new one after the call.
- `dispatch()` is held until the transaction commits, and discarded if it rolls back.
- WebSocket sends go out immediately and are **not** rolled back with the SQL.

## Activation and hibernation

When an Atom wakes up, the runtime starts PHP, applies pending migrations, and calls `onActivation()`. Cloudflare may hibernate an idle Atom while keeping its SQLite database and hibernatable WebSockets. The next invocation, WebSocket message or close, or timer wakes it up again from its stored state. A deployment can also [shut down](https://developers.cloudflare.com/durable-objects/concepts/durable-object-lifecycle/#shutdown-behavior) an active Atom, interrupting work and closing sockets. Shutdown discards in-memory state but preserves the database. See [Stage application and Atom releases](/guides/deploy/#stage-application-and-atom-releases).

`onDeactivation()` is best-effort cleanup, not a durability hook. Do not depend on it for writes that must happen.

## Migrations

An Atom's schema lives in a `migrations/` directory next to its class: a numbered series like `001_create_events.sql`, `002_add_round_index.sql`. When an Atom wakes up, the runtime applies new migrations before your code runs.

A migration can also be a `.php` file, named the same way, that returns an object with an `up()` method.

Once deployed, migrations are append-only. Editing an applied migration changes its hash, and the next attempt to apply migrations fails.

## State belongs in SQLite

In-memory PHP state, such as object properties, disappears when the Atom shuts down or hibernates, which can happen at any time. Keep durable state in the SQLite database, and rebuild in-memory state from it when the Atom wakes up.
