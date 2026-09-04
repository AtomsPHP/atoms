---
title: WebSockets and timers
description: Durable connections, broadcasts, and named one-shot timers.
---

## WebSockets

An Atom receives WebSocket events through three lifecycle handlers:
`onConnect()`, `onMessage()`, and `onDisconnect()`. The base Atoms class
defines no behavior for these methods. Override them when your Atom needs to
react to an event. If you only need to broadcast to all connected clients - for
example, a real-time read-only dashboard - you don't need any of these.

Client connections are grouped into channels. A client names its channels in the
`?channels=` query parameter when it connects, comma-separated when there is
more than one (`?channels=lobby,game`). The Atom can then call
`broadcast('lobby', ...)` to send a message to every connection that joined
`lobby`.

```php
public function onConnect(Connection $connection, array $params): void
{
    // $params holds every query key from the connection URL — except the
    // reserved `ticket` key — merged with any claims a verified ticket
    // carried.
}

public function onMessage(Connection $connection, Message $message): void
{
    // Runs when a message is received from a connected client
    $this->broadcast('lobby', ['message' => $message->payload()]);
}

public function onDisconnect(Connection $connection): void
{
    // You can attempt cleanup here
}
```

### Connecting

A browser authenticates with a short-lived connection ticket carried in the URL. Your application mints the ticket
and builds the connection URL from it:

```php
# Laravel - Symfony and plain PHP examples are at the bottom of this page.
use App\Atoms\GameRoom;
use Atoms\Laravel\Facades\Atoms;

$ticket = Atoms::ticket(GameRoom::class, $roomId, ['client_id' => (string) $user->id]);
$url = Atoms::wsUrl(GameRoom::class, $roomId, [
    'channels' => ['lobby'],
    'ticket' => (string) $ticket,
]);
```

A ticket is a string signed with a key derived from the shared secret, scoped to one Atom instance and valid for 60 seconds by default.

Ticket claims — `client_id` above — are merged over the browser's own query parameters on connect, so a browser cannot replace them in the `$params` given to an Atom's `onConnect()` method. A ticket holds at most 16 claims totaling 2048 bytes.

The Worker verifies a ticket's signature and expiry at the edge, before accepting the connection.

### Sending and receiving messages

`Connection` exposes `id()`, `send(string $payload)`, `sendJson(array $payload)`, and `close()`.

There are multiple ways to send messages, and each one delivers a different shape to the client:
  - `send()` sends a raw string to a single connection
  - `sendJson()` encodes an array as a bare JSON object, and sends it to a single connection
  - `broadcast()` messages all connections on a given channel
    - these message frames are wrapped in an envelope — `{"kind":"broadcast","channel":"lobby","payload":{...}}` — so a client listening on several channels can tell which one a frame came from.

To reach every connection at once, choose a global channel name and have all connections join it (e.g. `channels=all`).

A client message arrives in `onMessage()` as a `Message`. `payload()` returns its content as a string, and `json()` decodes a JSON payload to an array — the inbound half of `sendJson()`. Clients may also send binary frames: `payload()` carries the raw bytes, and `isBinary()` says which kind arrived. In the other direction, `send()` produces a text frame for a valid UTF-8 payload and a binary frame for anything else.

### Behavior

Connections use Cloudflare's Hibernation API, so an open socket survives an eviction and a frame or close event wakes the Atom back up.

A WebSocket message sent inside a database transaction will send even if the transaction later rolls back. A send accepted during `onDisconnect()` can still be dropped by the closing socket.

An inbound message over the size cap (128 KiB by default) closes the socket with code `1009` instead of reaching `onMessage()`.

`onDisconnect()` normally fires exactly once per connection, but an eviction can land between the platform's duplicate close events and deliver it a second time. Make cleanup safe to run twice.

## Timers

A timer is a named, durable, one-shot alarm. Schedule one with a name and a `\DateTimeImmutable` due time, and handle it in `onTimer()`:

```php
$this->timers()->schedule('expire-lobby', new \DateTimeImmutable('+10 minutes'));

protected function onTimer(string $name): void
{
    if ($name === 'expire-lobby') {
        $this->db()->execute('UPDATE rooms SET expired = 1');
    }
}
```

Scheduling a name that already exists replaces its due time. Canceling a name that doesn't exist is a no-op. Scheduling joins the surrounding database transaction: if the transaction rolls back, the timer is never created. You can schedule as many named timers as you need; Atoms tracks them in the Atom's database and fires each one when it comes due.

A timer fires at most once. If the handler throws, the failure is logged and the timer is finished. Write handlers that are safe to run once, and schedule a new timer when you need another attempt.

Use a timer wherever you would reach for `sleep()` or a polling loop. On the deployed runtime the clock does not advance during a turn, and the bundled PHPStan rules flag both patterns.

## Minting tickets in Symfony

`Atoms\Client\AtomsClient` and `Atoms\Client\Tickets\TicketIssuer` are both autowireable (service ids `atoms.client` and `atoms.ticket_issuer`). `TicketIssuer::issue()` takes the wire type name — the Atom class basename — which `AtomsClient::wireType()` derives:

```php
use App\Atoms\GameRoom;
use Atoms\Client\AtomsClient;
use Atoms\Client\Tickets\TicketIssuer;

class GameRoomController
{
    public function __construct(
        private AtomsClient $atoms,
        private TicketIssuer $tickets,
    ) {}

    public function connectUrl(string $roomId, string $userId): string
    {
        $ticket = $this->tickets->issue(
            AtomsClient::wireType(GameRoom::class),
            $roomId,
            ['client_id' => $userId],
        );

        return $this->atoms->wsUrl(GameRoom::class, $roomId, [
            'channels' => ['lobby'],
            'ticket' => (string) $ticket,
        ]);
    }
}
```

## Minting tickets in plain PHP

The [plain-PHP example](https://github.com/AtomsPHP/atoms/tree/main/examples/plain-php) builds an `AtomsClient` and a `TicketIssuer` through its `AtomsBootstrap` class and exposes them as `client()` and `tickets()`:

```php
use App\Atoms\GameRoom;
use Atoms\Client\AtomsClient;

$ticket = $app->tickets()->issue(
    AtomsClient::wireType(GameRoom::class),
    $roomId,
    ['client_id' => $userId],
);

$url = $app->client()->wsUrl(GameRoom::class, $roomId, [
    'channels' => ['lobby'],
    'ticket' => (string) $ticket,
]);
```
