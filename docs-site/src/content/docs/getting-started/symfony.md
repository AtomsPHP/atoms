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
    api_key: '%env(ATOMS_API_KEY)%'
    timeout: 10.0
    max_attempts: 3
    platform_public_key: '%env(ATOMS_PLATFORM_PUBLIC_KEY)%'
    callback_path: /atoms/callback
    callback_timestamp_window: 300
    http_client: null
    psr17_factory: null
    methods_classes:
        - App\Atoms\GameRoom\Methods
```

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

The callback endpoint accepts POST only and is intentionally outside session and CSRF middleware. Ed25519 signatures, timestamps, and nonces authenticate callback requests.

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
