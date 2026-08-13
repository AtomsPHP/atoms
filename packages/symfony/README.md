# atoms/symfony

Symfony bundle for Atoms: wires `Atoms\Client\AtomsClient`, the inbound
callback stack (Ed25519 verification, Methods dispatch, AtomJob
reconstruction), a Messenger-backed queue bridge, and thin console wrappers
around the `atoms` binary — all on top of `atoms/client` alone.
`atoms/client` is deliberately framework-free (integration-plan §5.3), so
this bundle depends on `atoms/client` and Symfony components only; it never
needs `Atoms\Laravel\*` or `Illuminate\*` (`tests/LayeringTest.php` is the
mechanical check, and `docs/conventions.md` the rule it enforces).

## Install

`config/bundles.php`:

```php
return [
    // ...
    Atoms\Symfony\AtomsBundle::class => ['all' => true],
];
```

`config/packages/atoms.yaml`:

```yaml
atoms:
    environment: '%env(APP_ENV)%'
    endpoint: https://atoms.your-subdomain.workers.dev   # your own deployed Worker
    api_key: '%env(ATOMS_API_KEY)%'                      # null when the Worker's ATOMS_APP_KEY is unset
    timeout: 10.0
    max_attempts: 3
    platform_public_key: '%env(ATOMS_PLATFORM_PUBLIC_KEY)%'
    callback_path: /atoms/callback
    callback_timestamp_window: 300   # seconds of clock skew a callback's timestamp may deviate before rejection
    http_client: null                # service id, or null to auto-detect / fall back to Guzzle
    psr17_factory: null              # service id, or null to auto-detect / fall back to Guzzle
    methods_classes:
        - App\Atoms\GameRoom\Methods   # only needed for #[MethodsFor] overrides
```

## Mount the callback route

Import the `atoms` resource type from your own routing, e.g.
`config/routes/atoms.yaml`:

```yaml
atoms:
    resource: .
    type: atoms
```

There is no vendor path to import — `Atoms\Symfony\Routing\AtomsRouteLoader`
is registered as a `routing.loader`-tagged service and resolves the `atoms`
type itself. It always mounts at the bundle's current `atoms.callback_path`,
so reconfiguring that value moves the route with it; nothing else needs to
change.

## What's wired

- `Atoms\Client\AtomsClient` — the RPC stub-proxy client, service id
  `atoms.client` (public; the `AtomsClient::class` service itself is private).
- The callback stack — `Ed25519Verifier`, `InMemoryNonceStore`,
  `MethodsResolver`, `CallbackKernel` — plus
  `Atoms\Symfony\Controller\CallbackController`, which converts Symfony
  `Request`/`Response` to/from PSR-7 by hand against the plain
  `ServerRequestFactoryInterface`/`StreamFactoryInterface` (no
  `psr/http-message-bridge` dependency); routed automatically as described
  above.
- A PSR-17 factory (`ServerRequestFactoryInterface` + `StreamFactoryInterface`
  + `ResponseFactoryInterface` in one — `GuzzleHttp\Psr7\HttpFactory` and
  `Nyholm\Psr7\Factory\Psr17Factory` both qualify) resolved, in order: the
  service id in `psr17_factory`, else a bundled `GuzzleHttp\Psr7\HttpFactory`
  (clear exception if `guzzlehttp/psr7` isn't installed). Backs
  `CallbackKernel`, `CallbackController` and `AtomsClient` alike. Resolved in
  a compiler pass so bundle registration order never matters.
- A PSR-18 client resolved, in order: the service id in `atoms.http_client`,
  else an app-defined `Psr\Http\Client\ClientInterface` service, else a
  `GuzzleHttp\Client` (clear exception if `guzzlehttp/guzzle` isn't
  installed). Resolved in a compiler pass so bundle registration order never
  matters.
- `Atoms\Client\Callback\QueueBridge`: `Atoms\Symfony\Messenger\MessengerQueueBridge`
  when `symfony/messenger` is installed and the app has a message bus
  service, wrapping dispatched `AtomJob`s as `AtomJobMessage` (normalized,
  JSON-safe constructor args only) and handling them back via
  `AtomJobHandler`; otherwise `Atoms\Client\Callback\NullQueueBridge`, which
  throws a clear "configure a QueueBridge" exception on first use.
- Methods resolution: every `methods_classes` entry is registered on the
  `MethodsResolver` by name (for `#[MethodsFor]` overrides) *and* as an
  autowired service, collected into a `Symfony\Component\DependencyInjection\ServiceLocator`
  that `CallbackKernel` consults first — so a listed Methods class can itself
  take app services as constructor dependencies. Anything not listed is
  instantiated with `new $class()`.
- A logger: `CallbackKernel` takes the app's `logger` service if one exists
  (Monolog via FrameworkBundle, or anything else bound to that id) and `null`
  otherwise — never a hard dependency on `psr/log`'s presence being wired up.
- `atoms:deploy`, `atoms:rollback`, `atoms:list` — thin console wrappers that
  shell out to the real `atoms` binary (discovered via `vendor/bin/atoms`,
  then `$PATH`, then `packages/cli/bin/atoms`); registered only when
  `symfony/console` is present.

## Supplying your own

Every auto-detected collaborator has an explicit override point:

- **HTTP client** — set `http_client` to a service id, or just register your
  own `Psr\Http\Client\ClientInterface` service and leave it null.
- **PSR-17 factory** — set `psr17_factory` to a service id implementing
  `ServerRequestFactoryInterface`, `StreamFactoryInterface` and
  `ResponseFactoryInterface`.
- **Queue bridge** — install `symfony/messenger` and register a message bus,
  or bind your own `Atoms\Client\Callback\QueueBridge` implementation to that
  service id directly.
- **Nonce store** — the bundle wires `Atoms\Client\Callback\InMemoryNonceStore`
  (process-local, not shared across workers); alias
  `Atoms\Client\Callback\NonceStore` to your own implementation (e.g.
  Redis-backed) for a multi-process deployment.
- **Methods classes** — list them under `methods_classes` to get container
  resolution (and `#[MethodsFor]` support); anything else resolves by naming
  convention with `new $class()`.
- **Logger** — register any service under the id `logger` (FrameworkBundle
  does this for you when Monolog is installed).

## Not yet supported

Methods classes resolve from the container only when listed under
`methods_classes`; anything not listed there is instantiated with
`new $class()` rather than autowired, even if the class itself would
otherwise be autowirable.
