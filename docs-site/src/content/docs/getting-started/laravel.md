---
title: Laravel quickstart
description: Create, call, migrate, test, and run an Atom locally from a Laravel application.
---

The maintained example lives at [`examples/laravel/`](https://github.com/AtomsPHP/atoms/tree/main/examples/laravel). It is the executable companion to this guide.

<!-- BEGIN generated Laravel compatibility -->
The adapter accepts Laravel 11, 12 and 13. CI tests Laravel 12 and 13. See [Laravel and Illuminate compatibility](/reference/compatibility/#laravel-and-illuminate).
<!-- END generated Laravel compatibility -->

## New Laravel application

This example uses Atoms 0.9.1, Laravel 13, PHP 8.3 and Node.js 22. From the
parent directory where you want the application, run:

```bash
composer create-project laravel/laravel atoms-demo '^13.0'
cd atoms-demo
composer require atoms/laravel:0.9.1 --with-all-dependencies
composer require --dev atoms/cli:0.9.1 atoms/testing:0.9.1 atoms/phpstan-rules:0.9.1
php artisan atoms:install
npm exec --yes --package=@atomsphp/runtime-cloudflare@0.9.1 -- \
  atoms-runtime-cloudflare init atoms-worker
(cd atoms-worker && npm ci)
```

The project is now initialized. Do not run `atoms init` or `atoms:install`
again. Keep the PHP packages and runtime on the same release. When using a
later release, run the matching scaffold command printed by `atoms:install`.

Continue with [Configure callback replay protection](#configure-callback-replay-protection).
For this local example, use the database driver and namespace
`atoms-demo:local`, then run `php artisan migrate`. Keep Laravel's generated
SQLite database. Next, [create the Atom](#create-an-atom), add its migration,
and [configure PHPStan](#check-the-atom). Set the local endpoint and start
Atoms before Laravel as shown in [Run it locally](#run-it-locally).

## Configure the adapter

For an existing Laravel application, install the packages from the
[installation guide](/getting-started/install/#php), then initialize Atoms and
publish the adapter configuration:

```bash
php artisan atoms:install
```

If you already ran `atoms init`, publish only the adapter configuration with
`php artisan vendor:publish --tag=atoms-config` instead. Readers who completed
the new-application steps can skip this section.

For a deployed Worker, set:

```dotenv
ATOMS_ENDPOINT=https://your-atoms-worker.example.workers.dev
ATOMS_SHARED_SECRET=base64-of-32-random-bytes
```

`ATOMS_ENDPOINT` is the application-side URL for ordinary Atom RPC.

`ATOMS_SHARED_SECRET` is required and must be identical on this application and the Worker. Set it on the Worker with `vendor/bin/atoms shared-secret:set`, not with `atoms:install` or `secrets:set`. See [Secrets and authentication](/guides/secrets/) for generating it, what it authenticates, and how to rotate it.

## Configure callback replay protection

Choose a shared nonce store before accepting `app()` or `dispatch()` callbacks.
Atoms ships both database and Redis implementations. No custom storage class
or container binding is required.

### Database

Set these application environment variables:

```dotenv
ATOMS_CALLBACK_NONCE_DRIVER=database
ATOMS_CALLBACK_NONCE_NAMESPACE=my-app:production
```

Atoms uses the application's default Laravel database connection. To choose
another connection from `config/database.php`, set
`ATOMS_CALLBACK_NONCE_DB_CONNECTION` to its name. All callback workers must
reach the same database. SQLite needs a shared persistent file on one host.

The package registers its migration when the database driver is selected. Run
your normal migration command before accepting callbacks:

```bash
php artisan migrate
```

This creates `atoms_callback_nonces` on the selected connection. Atoms opens a
separate connection for nonce writes so application transactions cannot undo
them. Schedule `php artisan atoms:nonces:prune` through Laravel's scheduler to
remove expired records.

### Redis

Configure a named Redis connection in Laravel's `config/database.php`, then
select it:

```dotenv
ATOMS_CALLBACK_NONCE_DRIVER=redis
ATOMS_CALLBACK_NONCE_NAMESPACE=my-app:production
ATOMS_CALLBACK_NONCE_REDIS_CONNECTION=atoms_nonces
```

The name `atoms_nonces` refers to your Laravel Redis connection. Configure its
URL, credentials, and database through Laravel's normal Redis settings. Both
PhpRedis and Predis are supported. Use a dedicated Redis database or instance
that application cache clears do not flush. Redis handles expiry; no database
migration or pruning command is needed for this backend.

### Retention and upgrades

The package's `config/atoms.php` exposes `callback_nonce` settings for the
driver, namespace, connection, retention, and clock allowance. If you published
an older configuration, merge the new `callback_nonce` section into it when customizing these settings.
Rebuild Laravel's configuration cache after changing environment values.

Leave `ATOMS_CALLBACK_NONCE_RETENTION_SECONDS` unset to derive retention from
`atoms.callback_timestamp_window` and
`ATOMS_CALLBACK_NONCE_CLOCK_ALLOWANCE_SECONDS`, which defaults to 60. The
[callback guide](/guides/callbacks/#retention) explains the calculation and
rollout requirements.

An unconfigured store rejects callbacks with `503`. Application boot and
outbound-only calls still work. `ATOMS_CALLBACK_NONCE_DRIVER=memory` explicitly
selects the development/test store; it does not protect callbacks across PHP
requests. Existing custom `NonceStore` bindings remain supported.

## Create an Atom

```bash
php artisan make:atom GameRoom --with-migration
```

```php title="app/Atoms/GameRoom.php"
<?php

namespace App\Atoms;

use Atoms\Atom;

/** @extends Atom<\Atoms\AtomMethods> */
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

Replace the generated migration's contents with this SQL. Keep only one
migration numbered `001`; rename the generated file to the path below if needed:

```sql title="app/Atoms/GameRoom/migrations/001_create_players.sql"
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
composer require --dev atoms/testing:^0.9
```

```php
use App\Atoms\GameRoom;
use Atoms\Testing\AtomHarness;

$room = AtomHarness::for(GameRoom::class, 'room-42');

self::assertSame(1, $room->invoke('join', ['ada']));
self::assertSame(2, $room->invoke('join', ['ada']));
```

## Check the Atom

Create `phpstan.neon` in the application root, or merge these settings into
an existing configuration:

```yaml title="phpstan.neon"
includes:
    - vendor/atoms/phpstan-rules/rules.neon

parameters:
    level: 8
    paths:
        - app/Atoms
```

Run validation and static analysis:

```bash
vendor/bin/atoms validate
vendor/bin/phpstan analyse
```

The rules check boundary types and the
[frozen-clock hazards](/reference/limits/#the-clock-does-not-advance-inside-a-turn).

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

### Verify two calls

With both servers running, open a third terminal in the application root:

```bash
php artisan tinker
```

Run these calls in Tinker:

```php
\Atoms\Laravel\Facades\Atoms::get(\App\Atoms\GameRoom::class, 'quickstart-1')->join('ada');
\Atoms\Laravel\Facades\Atoms::get(\App\Atoms\GameRoom::class, 'quickstart-1')->join('ada');
```

A new identity returns `1`, then `2`. Repeating the calls increments the
stored count. Use a new identity if you want to repeat the initial result.

### Troubleshoot the selected environment

If Wrangler selects an unexpected Worker after an interrupted run, follow
[Recover a stale Wrangler redirect](/guides/configuration/#recover-a-stale-wrangler-redirect).
Preserve `.wrangler/state` to keep the local Atom databases.

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
