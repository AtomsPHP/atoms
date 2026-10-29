---
title: Laravel quickstart
description: Create, call, migrate, test, and run an Atom locally from a Laravel application.
---

The maintained example lives at [`examples/laravel/`](https://github.com/AtomsPHP/atoms/tree/main/examples/laravel). It is the executable companion to this guide.

<!-- BEGIN generated Laravel compatibility -->
The adapter accepts Laravel 11, 12 and 13. CI tests Laravel 12 and 13. See [Laravel and Illuminate compatibility](/reference/compatibility/#laravel-and-illuminate).
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

## Configure callback replay protection

If your Atoms use `app()` or `dispatch()`, configure a shared nonce store before
accepting callbacks. PHP-FPM and multiple local web workers need it too. Add the
[database table and `DatabaseNonceStore` class](/guides/callbacks/#database-example),
then create this application configuration file:

```php title="config/atoms_callbacks.php"
<?php

return [
    'dsn' => env('ATOMS_NONCE_DSN'),
    'username' => env('ATOMS_NONCE_DB_USER'),
    'password' => env('ATOMS_NONCE_DB_PASSWORD'),
    'retention_seconds' => (int) env('ATOMS_NONCE_RETENTION_SECONDS', 900),
];
```

Set `ATOMS_NONCE_DSN` to a PDO DSN such as
`pgsql:host=database;dbname=app`, with the corresponding user and password.
For one host, you can use `sqlite:/absolute/path/to/callback-nonces.sqlite`
and leave the credentials unset. Create the table in that database first.
These are application settings for this example.

Add this binding to your `AppServiceProvider::register()` after the Atoms
package provider registers its defaults, and before any code resolves the
callback kernel:

```php title="app/Providers/AppServiceProvider.php"
$this->app->singleton(
    \Atoms\Client\Callback\NonceStore::class,
    static function (\Illuminate\Contracts\Foundation\Application $app): \Atoms\Client\Callback\NonceStore {
        $config = $app['config']->get('atoms_callbacks');

        return new \App\Callbacks\DatabaseNonceStore(
            new \PDO($config['dsn'], $config['username'], $config['password']),
            $config['retention_seconds'],
        );
    },
);
```

Laravel normally discovers the Atoms package provider before application
providers. If you register providers manually, preserve that order. The
database keeps nonces across requests; the singleton binding only reuses the
store object within a container. Keep retention long enough for your configured
`atoms.callback_timestamp_window`, as [Callbacks](/guides/callbacks/#store-callback-nonces)
explains.

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
composer require --dev atoms/testing:^0.8
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

Start the local Worker in one terminal:

```bash
vendor/bin/atoms dev --env staging --callback-url http://127.0.0.1:8000/atoms/callback
```

Once it is ready, start Laravel in another terminal:

```bash
PHP_CLI_SERVER_WORKERS=4 php artisan serve --host=127.0.0.1 --port=8000 --no-reload
```

Laravel needs at least two workers when an HTTP request invokes an Atom that calls back with `$this->app()`. One handles the waiting request; another handles the callback. Keep `--no-reload` so Laravel honors `PHP_CLI_SERVER_WORKERS`, and restart it after changing `.env`.

[PHP CLI server workers](https://www.php.net/manual/en/features.commandline.webserver.php) are for local development and require process forking. On native Windows, run both processes in WSL2, or use Nginx with PHP-FPM configured for at least two concurrent requests. If the processes run in separate containers or hosts, replace the loopback callback URL with an address the Worker can reach.

`atoms dev` creates a shared secret in `.env` if needed and copies it to the Worker's `.dev.vars`. Start Laravel afterward so it reads that value. No Cloudflare account is needed.

`--env` selects an environment from `atoms.json`; `--callback-url` overrides its callback address. See the [CLI reference](/reference/cli/) for other options and how settings are resolved.

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
