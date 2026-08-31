---
title: Lifecycle and persistence
description: How identity, activation, serialized turns, migrations, and hibernation fit together.
---

An Atom is identified by its PHP type and application-chosen id. Every pair maps deterministically to one Cloudflare Durable Object and one SQLite database.

## A turn at a time

An invocation, WebSocket event, or timer is a turn. The runtime serializes turns for one Atom residency, so two callers cannot concurrently mutate the same Atom. Different ids remain independent and can run in parallel.

Use SQLite transactions when several writes must succeed or roll back together:

```php
return $this->db()->transaction(function (): int {
    $this->db()->execute('UPDATE inventory SET stock = stock - 1 WHERE sku = ?', ['book']);
    $this->db()->execute('INSERT INTO orders (sku) VALUES (?)', ['book']);

    return (int) $this->db()->lastInsertId();
});
```

`app()` is rejected inside a transaction because the synchronous callback would hold the Durable Object storage callback open across an HTTP round trip. `dispatch()` is buffered until commit and discarded on rollback. WebSocket sends are immediate and are **not** rolled back with SQL.

## Activation and hibernation

The Worker constructs PHP when an Atom becomes resident, applies pending migrations, and calls `onActivation()`. Cloudflare may later evict idle compute while retaining SQLite and hibernatable WebSockets. On the next invocation, frame, close event, or alarm, the runtime reconstructs the Atom from its durable state.

`onDeactivation()` is best-effort cleanup, not a durability hook. Do not depend on it for writes that must happen.

## Migrations

Migrations are ordered files named `NNN_name.sql` or `NNN_name.php` beside an Atom. They are hashed into the build manifest and applied once, in ascending order, before user code runs.

Once deployed, migrations are append-only. Editing an applied migration changes its hash and raises a stable migration error instead of silently rewriting history. Add the next numbered migration for every schema change.

## State belongs in SQLite

PHP object properties disappear when the residency is evicted. Durable state belongs in `$this->db()`. In-memory fields are only per-residency caches and must always be reconstructible.
