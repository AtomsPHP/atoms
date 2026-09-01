---
title: Laravel quickstart
description: Create, call, migrate, and test an Atom from a Laravel application.
---

The maintained example lives at [`examples/laravel/`](https://github.com/AtomsPHP/atoms/tree/main/examples/laravel). It is the executable companion to this guide.

## Configure the adapter

Initialize the Atoms project and publish the Laravel adapter configuration:

```bash
php artisan atoms:install
```

Set your deployed Worker endpoint:

```dotenv
ATOMS_ENDPOINT=https://your-atoms-worker.example.workers.dev
ATOMS_ENVIRONMENT=production
ATOMS_SHARED_SECRET=base64-of-32-random-bytes
```

`ATOMS_SHARED_SECRET` is required and must be identical on this application and the Worker (`openssl rand -base64 32` generates one). Every credential on that boundary — the outbound `Authorization` bearer, WebSocket ticket signing, and inbound callback verification — is derived from it; the value itself is never sent anywhere. Set it on the Worker with `vendor/bin/atoms shared-secret:set --env production`, not with `atoms:install` or `secrets:set`. See [Callbacks](/guides/callbacks/#configure-the-channel) for the full mechanism.

## Create an Atom

```bash
php artisan make:atom GameRoom --with-migration
```

```php
namespace App\Atoms;

use Atoms\Atom;

class GameRoom extends Atom
{
    public function join(string $playerId): int
    {
        return $this->db()->transaction(function (\Atoms\Database $db) use ($playerId): int {
            $db->execute(
                'INSERT INTO players (player_id, visits) VALUES (?, 1) '
                . 'ON CONFLICT(player_id) DO UPDATE SET visits = visits + 1',
                [$playerId],
            );

            return (int) $db->query(
                'SELECT visits FROM players WHERE player_id = ?',
                [$playerId],
            )[0]['visits'];
        });
    }
}
```

Add an append-only migration beside the Atom using the layout produced by the generator:

```sql
CREATE TABLE players (
    player_id TEXT PRIMARY KEY,
    visits INTEGER NOT NULL DEFAULT 0
);
```

## Call it

The facade returns a typed RPC proxy. The Atom id is the durable identity:

```php
use App\Atoms\GameRoom;
use Atoms\Laravel\Facades\Atoms;

$count = Atoms::get(GameRoom::class, 'room-42')->join('ada');
```

Pass an `Atoms\Client\CallOptions` as a third argument to `get()` to control retry-on-timeout, the idempotency key, or the trace header for one call — see [Per-call options](/reference/limits/#per-call-options).

Calls with the same Atom type and id are serialized by the Durable Object. Different ids can execute independently.

## Test it without Cloudflare

Use `atoms/testing` for fast local tests of Atom behavior, migrations, callbacks, broadcasts, and timers:

```bash
composer require --dev atoms/testing:^0.4
```

```php
use App\Atoms\GameRoom;
use Atoms\Testing\AtomHarness;

$room = AtomHarness::for(GameRoom::class, 'room-42');

self::assertSame(1, $room->invoke('join', ['ada']));
self::assertSame(2, $room->invoke('join', ['ada']));
```

Before deploying, run PHPStan with `vendor/atoms/phpstan-rules/rules.neon` included. It catches values that cannot cross the boundary and the frozen-clock hazards described in [Limits](/reference/limits/#the-clock-does-not-advance-inside-a-turn).

## Build and deploy

```bash
vendor/bin/atoms validate
vendor/bin/atoms build
vendor/bin/atoms deploy --env production
```

See [Deploy](/guides/deploy/) for credentials, callback configuration, and propagation behavior.
