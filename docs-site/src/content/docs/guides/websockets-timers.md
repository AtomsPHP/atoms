---
title: WebSockets and timers
description: Durable connections, broadcasts, and named one-shot timers.
---

## WebSockets

Override the lifecycle handlers on an Atom:

```php
public function onConnect(Connection $connection, array $params): void
{
    $connection->join('room');
}

public function onMessage(Connection $connection, Message $message): void
{
    $this->broadcast('room', ['message' => $message->text()]);
}

public function onDisconnect(Connection $connection): void
{
    // Best-effort presence cleanup.
}
```

Connections use Cloudflare’s Hibernation API and can wake an evicted Atom on a frame or close event. Text and binary frames preserve their distinct wire formats. Binary application payloads crossing the JSON bridge are base64 encoded.

WebSocket sends are not transactional. A frame sent inside a database transaction may already be visible even if the transaction later rolls back. A send accepted during `onDisconnect()` can still be dropped by the closing socket.

## Timers

Timers are named, durable, one-shot alarms:

```php
$this->timers()->schedule('expire-lobby', $unixTimestamp);

protected function onTimer(string $name): void
{
    if ($name === 'expire-lobby') {
        $this->db()->execute('UPDATE rooms SET expired = 1');
    }
}
```

Scheduling the same name replaces its due time. Canceling a missing name is a successful no-op. Cloudflare stores one physical alarm per Durable Object; Atoms multiplexes the named timers in SQLite and drains due work within the alarm event.

Timer delivery is at-most-once. An uncaught handler failure is logged, but that timer is not retried. Make handlers safe to run once and schedule a new timer explicitly if the domain needs another attempt.

Use timers instead of `sleep()` or elapsed-time loops. The guest clock does not advance inside a deployed turn; the static rules raise [ATOMS-E101](/reference/errors/#atoms-e101) and [ATOMS-E102](/reference/errors/#atoms-e102).
