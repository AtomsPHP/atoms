---
title: Lifecycle and persistence
description: How identity, activation, serialized turns, migrations, and hibernation fit together.
---

An Atom instance is identified by its PHP class name and an ID that you give it.

## A turn at a time

An invocation (from your PHP app), a WebSocket event, or a [timer](/guides/websockets-timers/#timers) is a turn. Durable Objects serialize turns, so two callers of an Atom instance with ID "Document-123" cannot concurrently mutate that document's state.

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

`app()` is rejected inside a transaction because of a runtime limitation: the synchronous callback would hold the Durable Object storage callback open across an HTTP round trip. `dispatch()` is buffered until commit and discarded on rollback. WebSocket sends are immediate and are **not** rolled back with SQL.

## Activation and hibernation

The Worker constructs PHP when an Atom wakes up, applies pending migrations, and calls `onActivation()`. Cloudflare may later evict idle compute while retaining SQLite and hibernatable WebSockets. On the next invocation, frame, close event, or alarm, the runtime reconstructs the Atom from its durable state.

`onDeactivation()` is best-effort cleanup, not a durability hook. Do not depend on it for writes that must happen.

## Migrations

An Atom's schema lives in a `migrations/` directory next to its class: a numbered series like `001_create_events.sql`, `002_add_round_index.sql`. When an Atom wakes up, the runtime applies new migrations before your code runs.

Migrations can also be a `.php` file (same naming convention as the `.sql` files) returning an object with an `up()` method.

Once deployed, migrations are append-only. Editing an applied migration changes its hash and would cause an error when migrations are next applied.

## State belongs in SQLite

PHP in-memory state (object properties, etc) disappears when the Atom shuts down or hibernates, which can happen at any time. Durable state belongs in the SQLite database, and it can be used to regenerate in-memory state when an Atom reactivates.
