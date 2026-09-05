---
title: Laravel quickstart
description: Create, call, migrate, test, and run an Atom locally from a Laravel application.
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

`ATOMS_SHARED_SECRET` is required and must be identical on this application and the Worker. Set it on the Worker with `vendor/bin/atoms shared-secret:set`, not with `atoms:install` or `secrets:set`. See [Secrets and authentication](/guides/secrets/) for generating it, what it authenticates, and how to rotate it.

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

## Test it without Cloudflare

Use `atoms/testing` for fast local tests of Atom behavior, migrations, callbacks, broadcasts, and timers:

```bash
composer require --dev atoms/testing:^0.6
```

```php
use App\Atoms\GameRoom;
use Atoms\Testing\AtomHarness;

$room = AtomHarness::for(GameRoom::class, 'room-42');

self::assertSame(1, $room->invoke('join', ['ada']));
self::assertSame(2, $room->invoke('join', ['ada']));
```

Before deploying, run PHPStan with `vendor/atoms/phpstan-rules/rules.neon` included. It catches values that cannot cross the boundary and the frozen-clock hazards described in [Limits](/reference/limits/#the-clock-does-not-advance-inside-a-turn).

## Run it locally

`atoms dev` builds your Atoms and serves them through the real Worker runtime you scaffolded on your machine in the "Install" step. No Cloudflare account is needed:

```bash
vendor/bin/atoms dev --callback-url http://127.0.0.1:8000/atoms/callback
```

Point the application at the local Worker while it runs:

```dotenv
ATOMS_ENDPOINT=http://127.0.0.1:8787
ATOMS_ENVIRONMENT=staging
```

The shared secret takes care of itself locally: `atoms dev` generates one into `.env` when it is absent and projects it into the Worker's `.dev.vars` whenever the two differ, so the local Worker and the application always agree without you handling the value.

`--callback-url` tells the local Worker where your application's callback endpoint lives, so `app()` and `dispatch()` work against the `php artisan serve` process; set `callback_url` in `atoms.json` once and `atoms dev` picks it up automatically. `--port` moves the Worker off 8787, and `--no-build` reuses the bundle from the last build. See the [CLI reference](/reference/cli/) for the full option surface.

## Build and deploy

```bash
vendor/bin/atoms validate
vendor/bin/atoms build
vendor/bin/atoms deploy --env production
```

See [Deploy](/guides/deploy/) for credentials, callback configuration, and propagation behavior.
