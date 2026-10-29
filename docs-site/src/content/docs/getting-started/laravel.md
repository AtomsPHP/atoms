---
title: Laravel quickstart
description: Create, call, migrate, test, and run an Atom locally from a Laravel application.
---

The maintained example lives at [`examples/laravel/`](https://github.com/AtomsPHP/atoms/tree/main/examples/laravel). It is the executable companion to this guide.

<!-- BEGIN generated Laravel compatibility -->
The Laravel adapter allows Laravel 11, 12 and 13 through its Illuminate dependencies. CI tests Laravel 12 and 13. Laravel 11 is allowed but is not currently tested in CI. See [Laravel and Illuminate compatibility](/reference/compatibility/#laravel-and-illuminate).
<!-- END generated Laravel compatibility -->

## Configure the adapter

Initialize the Atoms project and publish the Laravel adapter configuration:

```bash
php artisan atoms:install
```

Set your deployed Worker endpoint:

```dotenv
ATOMS_ENDPOINT=https://your-atoms-worker.example.workers.dev
ATOMS_SHARED_SECRET=base64-of-32-random-bytes
```

`ATOMS_ENDPOINT` is the application-side URL for ordinary Atom RPC.

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
composer require --dev atoms/testing:^0.7
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

Set the application endpoint in `.env` before starting either process:

```dotenv
ATOMS_ENDPOINT=http://127.0.0.1:8787
```

In one terminal, start the local Worker. `atoms dev` builds your Atoms and serves them through the real Worker runtime you scaffolded in the "Install" step. No Cloudflare account is needed:

```bash
vendor/bin/atoms dev --env staging --callback-url http://127.0.0.1:8000/atoms/callback
```

Wait for the Worker to be ready, then start Laravel in another terminal with concurrent request handling:

```bash
PHP_CLI_SERVER_WORKERS=4 php artisan serve --host=127.0.0.1 --port=8000 --no-reload
```

Keep `--no-reload`: Laravel can otherwise warn and fall back to a single worker despite `PHP_CLI_SERVER_WORKERS`. Restart this server after changing `.env`, since automatic environment reloading is disabled.

A request from a browser or HTTP client can enter Laravel, invoke an Atom, and wait while `$this->app()` calls back into Laravel. That callback needs another available application worker. With a single worker, the original request waits for the callback and the callback waits for the original request to finish. Increasing callback timeouts does not remove this circular wait. A call from Tinker does not occupy an application HTTP worker, and `AtomHarness` runs callbacks in process, so success there does not verify this HTTP path.

[PHP CLI server workers](https://www.php.net/manual/en/features.commandline.webserver.php) require a platform with process forking and are unavailable on native Windows. This is a development-only server. On Windows, run both processes inside WSL2 with a supported Linux PHP installation, or use [Nginx with PHP-FPM](https://laravel.com/docs/12.x/deployment#nginx) in a Linux environment. Configure the FPM pool to allow at least two simultaneous requests and serve Laravel's `public/` directory. Point `--callback-url` at the application's reachable address; if the Worker and application run in different containers or hosts, `127.0.0.1` refers to each process's own environment.

The shared secret takes care of itself locally: `atoms dev` generates one into `.env` when it is absent and projects it into the Worker's `.dev.vars` whenever the two differ. Starting Laravel after the Worker ensures it reads that value.

`--callback-url` tells the local Worker where your application's callback endpoint lives, so `app()` and `dispatch()` work against the `php artisan serve` process. `--callback-url` wins over everything; without it `atoms dev` uses `ATOMS_CALLBACK_URL` from the environment it was started with, then from `.env.atoms.<env>` beside `atoms.json`, then the selected environment's `callback_url`. Any of them may differ from a committed production callback. `--env` is required and names one of *your* environments — `dev` reads that entry's `debug_endpoints`, and falls back to its `callback_url` only when nothing nearer supplies one, which is why the flag above matters. `--port` moves the Worker off 8787, and `--no-build` reuses the bundle from the last build. See the [CLI reference](/reference/cli/) for the full option surface.

## Build and deploy

```bash
vendor/bin/atoms validate
vendor/bin/atoms build
vendor/bin/atoms deploy --env production
```

See [Deploy](/guides/deploy/) for credentials, callback configuration, and propagation behavior.

### Through Artisan

The service provider registers wrappers for the commands you run most:
`atoms:deploy`, `atoms:dev`, `atoms:rollback`, `atoms:list`, `atoms:install`
and `make:atom`. Each shells out to the same `atoms` binary and forwards your
options, so `php artisan atoms:deploy --env production` and
`vendor/bin/atoms deploy --env production` resolve identically.

Identically is the deliberate part. Artisan runs after Laravel has loaded your
application's `.env`, and the wrapper hands the child the environment the
*command* was started with rather than the one the framework built — so a
local `ATOMS_CALLBACK_URL` in your `.env` is not a deployment input, while one
from your shell or from CI still is. Note also that Laravel reads its own
`--env` off the command line, so `--env production` makes it load
`.env.production`; that no longer decides anything on the Atoms side. See
[Framework
commands](/guides/environments/#framework-commands).
