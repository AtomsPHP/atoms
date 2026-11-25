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

    return (int) $this->db()->pdo()->lastInsertId();
});
```

### Transaction behavior

- `app()` can't be called inside a transaction ([ATOMS-E082](/reference/errors/#atoms-e082)). Read what you need from your application before opening the transaction, or close it and open a new one after the call.
- `dispatch()` is held until the transaction commits, and discarded if it rolls back.
- WebSocket sends go out immediately and are **not** rolled back with the SQL.

## Activation and hibernation

When an Atom wakes up, the runtime starts PHP, applies pending migrations, and calls `onActivation()`. Cloudflare may hibernate an idle Atom while keeping its SQLite database and hibernatable WebSockets. The next invocation, WebSocket message or close, or timer wakes it up again from its stored state.

[Deployments can restart active Atoms](/guides/deploy/#verify-the-deployment), interrupting calls and closing sockets. In-memory state is lost; committed SQLite data survives.

`onDeactivation()` is best-effort cleanup, not a durability hook. Do not depend on it for writes that must happen.

## Destroying an Atom

To remove an Atom instance and its durable state, call `destroy()` with its class (or wire type) and ID:

```php
// Laravel
$removed = Atoms::destroy(GameRoom::class, 'room-42');

// Symfony or plain PHP (injected AtomsClient)
$removed = $atoms->destroy(GameRoom::class, 'room-42');
```

- **Boolean result:** returns `true` when this request removed an existing Atom, and `false` when the ID was already absent. Deleting a missing ID leaves no SQLite tables, rows, or alarms behind.
- **Teardown:** deletion is serialized behind in-flight turns on the same ID. If the Atom is currently warm and resident in memory, the runtime runs `onDeactivation()` best-effort under the normal turn limits and logs any failure; if the Atom is cold or hibernated, it is deleted without waking PHP or running customer hooks. After teardown settles, the runtime closes connected and hibernated WebSockets with code `1000` and reason `Atom destroyed`, clears all scheduled timers and the platform alarm, and wipes the Atom's SQLite storage before returning `true`.
- **Reuse and tickets:** a subsequent authenticated invocation or WebSocket upgrade for the same ID creates a fresh Atom from scratch, re-running migrations and `onActivation()`. Stale socket frames, close events, and timers from the deleted Atom are fenced off and never touch the new incarnation. Previously issued stateless WebSocket tickets remain valid until their normal expiry, so applications that need permanent removal must control reuse of that ID and account for outstanding tickets.
- **Single-attempt retry policy:** unlike ordinary RPC invocations, `destroy()` sends a **single** `DELETE` request and does not auto-retry on transport or platform failures. If the server committed the deletion and the response was lost, the outcome is unknown — a blind retry could destroy a newly recreated Atom under the same ID. Control reuse of that ID during any application-level retry window.

## Migrations

An Atom's schema lives in a `migrations/` directory next to its class: a numbered series like `001_create_events.sql`, `002_add_round_index.sql`. When an Atom wakes up, the runtime applies new migrations before your code runs.

A PHP migration uses the same numbered filename, such as
`001_create_events.php`. It must return an object that implements
`Atoms\Migrations\Migration` with `up(Atoms\Database $db): void`:

```php
<?php
return new class implements \Atoms\Migrations\Migration {
    public function up(\Atoms\Database $db): void
    {
        $db->execute('CREATE TABLE events (id INTEGER PRIMARY KEY)');
    }
};
```

Each Atom's database records its highest applied migration version. At
activation, Atoms runs migrations with a higher version.

Keep deployed migrations unchanged. To change the schema, add a migration
with a higher version.

## State belongs in SQLite

In-memory PHP state, such as object properties, disappears when the Atom shuts down or hibernates, which can happen at any time. Keep durable state in the SQLite database, and rebuild in-memory state from it when the Atom wakes up.
