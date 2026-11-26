---
title: WebSockets and timers
description: Durable connections, broadcasts, and named one-shot timers.
---

## WebSockets

An Atom receives WebSocket events through three lifecycle handlers:
`onConnect()`, `onMessage()`, and `onDisconnect()`. They do nothing in the
base `Atom` class; override the ones your Atom needs. An Atom that only
broadcasts, such as a read-only live dashboard, needs none of them.

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

### Broadcast-only Atoms

Calling `publish()` sends an update to clients connected with `channels=all`:

```php title="app/Atoms/Dashboard.php"
<?php

declare(strict_types=1);

namespace App\Atoms;

use Atoms\Atom;

final class Dashboard extends Atom
{
    public function publish(): void
    {
        $this->broadcast('all', ['updated' => true]);
    }
}
```

### Connecting

A browser authenticates with a short-lived connection ticket carried in the URL. Your application mints the ticket
and builds the connection URL from it:

```php
# Laravel. Symfony and plain-PHP versions are at the end of this page.
use App\Atoms\GameRoom;
use Atoms\Laravel\Facades\Atoms;

$ticket = Atoms::ticket(GameRoom::class, $roomId, ['client_id' => (string) $user->id]);
$url = Atoms::wsUrl(GameRoom::class, $roomId, [
    'channels' => ['lobby'],
    'ticket' => (string) $ticket,
]);
```

A ticket is a signed string, scoped to one Atom instance and valid for 60
seconds by default. The Worker checks its signature and expiry before it
accepts the connection.

Ticket claims, such as `client_id` above, are merged over the browser's own
query parameters, so a browser cannot replace them in the `$params` passed to
`onConnect()`. A ticket holds at most 16 claims totaling 2048 bytes.

### Reconnecting to a game

Keep the player's seat and the last accepted move in the Atom's database.
Mint every ticket for this game with a `client_id` from the authenticated
user or an ID stored in the application's session. A new socket gets a new
connection ID. In `onConnect()`, look up the seat by `client_id`, associate
it with the new connection, and send the current game and revision to that
socket. The [two-player example](https://github.com/AtomsPHP/atoms/tree/main/examples/reconnecting-room)
shows the migration and handlers. When you create a room, call
`Atoms::get(Turns::class, $roomId)->start($firstPlayerId, $secondPlayerId)`
once to assign the two seats. Each player then requests their own ticket for
that room.

The `atoms/client` Composer package includes a browser module. Import it from
your application's JavaScript bundle. This example uses a Laravel POST route
that returns the signed URL above and a page with a CSRF token.

```js title="resources/js/room.js"
import { reconnectSocket } from '../../vendor/atoms/client/resources/reconnect-socket.mjs';

const stop = reconnectSocket(async (signal) => {
    const response = await fetch(`/rooms/${encodeURIComponent(roomId)}/socket`, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
        },
        signal,
    });
    if ([401, 403, 404, 419].includes(response.status)) {
        showRoomUnavailable();
        return null;
    }
    if (!response.ok) throw new Error('Could not get a connection ticket');
    return (await response.json()).url;
}, (socket) => {
    socket.addEventListener('message', ({ data }) => {
        const frame = JSON.parse(data);
        if (frame.kind === 'welcome') resumeGame(frame.seat, frame.game, socket);
        if (frame.kind === 'broadcast') updateGame(frame.payload);
    });
    socket.addEventListener('close', () => disableMoves());
});
```

After a network failure or Worker restart, the helper requests a fresh ticket.
The delay starts at one second and doubles to a maximum of 15 seconds. Receiving
a message resets the delay. Normal closure, policy errors, and game-specific
close codes end the retries. Return `null` from the ticket request to stop after
a refusal. Call `stop()` when leaving the room to close the socket and cancel a
pending request or retry.

Enable moves after the welcome frame arrives. Send the displayed revision with
each move and check it before updating the game. A move may have reached the
Atom just before the socket closed. The snapshot tells both players which move
the Atom accepted.

### Sending and receiving messages

`Connection` exposes `id()`, `send(string $payload)`, `sendJson(array $payload)`, and `close()`.

| Call | Sends to | The client receives |
|---|---|---|
| `$connection->send($string)` | one connection | the string as is |
| `$connection->sendJson($array)` | one connection | the array as a bare JSON object |
| `$this->broadcast($channel, $array)` | every connection on the channel | `{"kind":"broadcast","channel":"lobby","payload":{...}}` |

The broadcast envelope names the channel so a client on several channels can
tell where a frame came from. To reach every connection at once, have all
clients join one shared channel, such as `channels=all`.

`send()` sends a text frame when the payload is valid UTF-8 and a binary frame
otherwise.

A client message arrives in `onMessage()` as a `Message`. `payload()` returns
its content as a string, and `json()` decodes a JSON payload to an array.

Clients may also send binary frames. `payload()` then returns the raw bytes,
and `isBinary()` tells you which kind arrived.

### Behavior

An open socket stays connected when the Atom is evicted from memory. The next
message or close event wakes the Atom back up.

A message sent inside a database transaction goes out even if the transaction
later rolls back. A message sent during `onDisconnect()` can be dropped by the
closing socket.

An inbound message over the size limit (128 KiB by default) closes the socket
with code `1009` and never reaches `onMessage()`.

`onDisconnect()` can occasionally run twice for the same connection, so make
its cleanup safe to repeat.

## Timers

A timer is a named, one-shot alarm that survives eviction. Schedule one with
a name and a `\DateTimeImmutable` due time, and handle it in `onTimer()`:

```php
$this->timers()->schedule('expire-lobby', new \DateTimeImmutable('+10 minutes'));

protected function onTimer(string $name): void
{
    if ($name === 'expire-lobby') {
        $this->db()->execute('UPDATE rooms SET expired = 1');
    }
}
```

Scheduling a name that already exists replaces its due time, and
`$this->timers()->cancel($name)` on a name that doesn't exist does nothing. Inside
`$this->db()->transaction()`, scheduling and canceling roll back with the
transaction. An Atom can have up to 10,000 scheduled timers by default; see
[Limits](/reference/limits/#configurable-runtime-limits) to change it.

A timer is removed just before `onTimer()` runs, so it is never retried. If
the handler throws or the turn fails, the failure is logged and the timer is
gone. If you need another attempt, schedule a new timer yourself.

Use a timer instead of `sleep()` or a polling loop; see
[the clock does not advance inside a turn](/reference/limits/#the-clock-does-not-advance-inside-a-turn).

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
