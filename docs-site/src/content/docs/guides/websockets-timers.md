---
title: WebSockets and timers
description: Durable connections, broadcasts, and named one-shot timers.
---

## WebSockets

Override the lifecycle handlers on an Atom. Channel membership is fixed for
the life of a connection by the `?channels=` query parameter on the upgrade
request:

```php
public function onConnect(Connection $connection, array $params): void
{
    // $params holds every query key sent on the upgrade — except the
    // reserved `ticket` key — merged with any claims a verified ticket
    // carried.
}

public function onMessage(Connection $connection, Message $message): void
{
    $this->broadcast('room', ['message' => $message->payload()]);
}

public function onDisconnect(Connection $connection): void
{
    // Best-effort presence cleanup.
}
```

`Connection` exposes `id()`, `send(string $payload)`, `sendJson(array $payload)`, and `close()`. `send()` writes the given bytes verbatim; `sendJson()` normalizes and encodes an array as a bare JSON object with no envelope, the same encoder `broadcast()` uses to build its `{"kind":"broadcast",...}` frame. To reach every connection at once, have each of them join a channel your application names, such as `channels=all`.

### Connecting

A server-to-server caller connects like any other authenticated request, with the same `Authorization: Bearer` header, to `wss://your-worker.example.workers.dev/ws/GameRoom/room-42?channels=lobby`.

A browser's `new WebSocket(url)` cannot set that header, so it authenticates with a short-lived connection ticket instead. Your application mints the ticket itself — a local, synchronous computation, with no request to the Worker — and builds the connection URL from it:

```php
use App\Atoms\GameRoom;
use Atoms\Laravel\Facades\Atoms;

$ticket = Atoms::ticket(GameRoom::class, $roomId, ['client_id' => (string) $user->id]);
$url = Atoms::wsUrl(GameRoom::class, $roomId, [
    'channels' => ['lobby'],
    'ticket' => (string) $ticket,
]);
```

Outside Laravel, the same two calls are `Atoms\Client\Tickets\TicketIssuer::issue()` and `Atoms\Client\AtomsClient::wsUrl()` (both reachable through `Atoms\Symfony\AtomsBundle`'s `atoms.ticket_issuer`/`atoms.client` services, or through `AtomsBootstrap::create()`'s `PlainPhpApp::tickets()` in a plain-PHP host). Hand the resulting URL to the browser and connect directly.

A ticket is a signed `v1.<payload>.<sig>` string, scoped to one `{type, id}` pair, valid for 60 seconds by default (`AtomsConfig::$wsTicketTtlMs`, or a per-call `$ttlMs` argument to `issue()`/`ticket()`). It is reusable until it expires — not single-use — so a reconnect inside that window can retry the same URL without minting a new one. Its claims, `client_id` above, are merged over the browser's own query parameters on connect, server wins, so `onConnect()`'s `$params` carries a value the browser could not forge itself. Issuance throws `Atoms\Client\Exception\InvalidTicketClaims` ([ATOMS-E068](/reference/errors/#atoms-e068)) only for a scope or claims map that does not fit the protocol (at most 16 claims, 2048 bytes total). On any connection failure, issue a fresh ticket — a browser cannot see why an upgrade was rejected.

The Worker verifies a ticket's signature and expiry at the edge, before completing the upgrade. Minting stays in your application, where the secret already lives.

### Behavior

Connections use Cloudflare’s Hibernation API and can wake an evicted Atom on a frame or close event. Text and binary frames preserve their distinct wire formats. Binary application payloads crossing the JSON bridge are base64 encoded.

WebSocket sends are not transactional. A frame sent inside a database transaction may already be visible even if the transaction later rolls back. A send accepted during `onDisconnect()` can still be dropped by the closing socket.

## Timers

Timers are named, durable, one-shot alarms. `schedule()` takes a `\DateTimeImmutable`, not a raw timestamp:

```php
$this->timers()->schedule('expire-lobby', new \DateTimeImmutable('+10 minutes'));

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
