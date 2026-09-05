---
title: Symfony quickstart
description: Configure the Atoms bundle, then create, call, migrate, test, and run an Atom locally from a Symfony application.
---

## Register the bundle

```php
// config/bundles.php
return [
    // ...
    Atoms\Symfony\AtomsBundle::class => ['all' => true],
];
```

## Configure Atoms

```yaml
# config/packages/atoms.yaml
atoms:
    environment: '%env(APP_ENV)%'
    endpoint: 'https://your-atoms-worker.example.workers.dev'
    shared_secret: '%env(ATOMS_SHARED_SECRET)%'
    timeout: 10.0
    max_attempts: 3
    ws_ticket_ttl_ms: 60000
    callback_path: /atoms/callback
    callback_timestamp_window: 300
    http_client: null
    psr17_factory: null
    methods_classes:
        - App\Atoms\GameRoom\Methods
```

- `shared_secret` is `ATOMS_SHARED_SECRET`: required, and identical on this application and the Worker. Set it on the Worker with `vendor/bin/atoms shared-secret:set`, never through `secrets:set`. An optional `shared_secret_previous` (`ATOMS_SHARED_SECRET_PREVIOUS`) widens acceptance during a rotation window without changing what this application sends. See [Secrets and authentication](/guides/secrets/).
- `ws_ticket_ttl_ms` is how long a WebSocket connection ticket minted by this application stays valid, in milliseconds.
- `callback_path` is where the signed reverse-RPC callback is mounted.
- `callback_timestamp_window` is the permitted clock skew in seconds.
- `psr17_factory` names a service implementing the PSR-17 request, response, server-request, and stream factory interfaces. Leave it `null` to use the bundled Guzzle factory.
- `methods_classes` enables container construction and `#[MethodsFor]` overrides.

## Mount the callback route

```yaml
# config/routes/atoms.yaml
atoms:
    resource: .
    type: atoms
```

`AtomsRouteLoader` resolves the `atoms` resource type. There is no vendor directory to import. Changing `atoms.callback_path` moves the route automatically. Import the loader once; duplicate imports are rejected.

The callback endpoint accepts POST only and is intentionally outside session and CSRF middleware. HMAC-SHA256 signatures — keyed from `shared_secret`, alongside a timestamp and a nonce — authenticate callback requests; see [Callbacks](/guides/callbacks/#configure-the-channel).

## Create an Atom

```bash
vendor/bin/atoms make:atom GameRoom --with-migration
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

## Inject and call the client

```php
use App\Atoms\GameRoom;
use Atoms\Client\AtomsClient;

class JoinRoom
{
    public function __construct(private AtomsClient $atoms) {}

    public function __invoke(string $room, string $player): int
    {
        return $this->atoms->get(GameRoom::class, $room)->join($player);
    }
}
```

The public service id is `atoms.client`. The bundle resolves a PSR-18 client, PSR-17 factories, optional logger, Methods locator, replay store, and callback kernel.

## Dispatch through Messenger

When `symfony/messenger` and a message bus are present, the bundle wires `MessengerQueueBridge`. Otherwise it wires `NullQueueBridge`, which raises [ATOMS-E103](/reference/errors/#atoms-e103) when an Atom dispatches a job. Bind your own `QueueBridge` if Messenger is not your queue.

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

Point the application at the local Worker while it runs. Route `atoms.endpoint` through an environment variable so it can differ per environment:

```yaml
# config/packages/atoms.yaml
atoms:
    endpoint: '%env(ATOMS_ENDPOINT)%'
```

```dotenv
# .env.local
ATOMS_ENDPOINT=http://127.0.0.1:8787
```

The shared secret takes care of itself locally: `atoms dev` generates one into `.env.local` when it is absent and projects it into the Worker's `.dev.vars` whenever the two differ, so the local Worker and the application always agree without you handling the value.

`--callback-url` tells the local Worker where your application's callback endpoint lives, so `app()` and `dispatch()` work against the local web server; set `callback_url` in `atoms.json` once and `atoms dev` picks it up automatically. `--port` moves the Worker off 8787, and `--no-build` reuses the bundle from the last build. See the [CLI reference](/reference/cli/) for the full option surface.

## Build and deploy

```bash
vendor/bin/atoms validate
vendor/bin/atoms build
vendor/bin/atoms deploy --env production
```

See [Deploy](/guides/deploy/) for credentials, callback configuration, and propagation behavior.

## Shipped behavior

The complete shipped behavior and remaining container-instantiation limitation are maintained in the [`atoms/symfony` README](https://github.com/AtomsPHP/atoms/tree/main/packages/symfony#readme).
