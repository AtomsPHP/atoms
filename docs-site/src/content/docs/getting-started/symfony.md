---
title: Symfony quickstart
description: Configure the supported Atoms bundle, its route loader, callback stack, and Messenger bridge.
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

- `shared_secret` is `ATOMS_SHARED_SECRET`: base64 of 32 random bytes, identical on this application and the Worker, required. Every credential on that boundary — the outbound bearer, WebSocket ticket signing, and inbound callback verification — is derived from it; set it on the Worker with `vendor/bin/atoms shared-secret:set --env production`, never through `secrets:set`. An optional `shared_secret_previous` (`ATOMS_SHARED_SECRET_PREVIOUS`) widens acceptance during a rotation window without changing what this application sends.
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

The complete shipped behavior and remaining container-instantiation limitation are maintained in the [`atoms/symfony` README](https://github.com/AtomsPHP/atoms/tree/main/packages/symfony#readme).
