# atoms/symfony

> **STATUS: internal Phase 1 skeleton.** This is a layering test
> (integration-plan §5.3), not a supported product. The public API here can
> and will change without notice before the Phase 3 public alpha. Do not
> depend on this package outside the Atoms monorepo.

## Why this exists

`atoms/client` is designed to be framework-free: everything hard (RPC stub
proxies, retries/idempotency, the `CallbackKernel`, Methods resolution) lives
there so that both `atoms/laravel` and `atoms/symfony` can be thin adapters
over it. The cheapest way to prove that design holds is to actually build the
second adapter — during Phase 1, before the `atoms/client` API is frozen —
and see whether it needs anything `atoms/laravel` has that `atoms/client`
doesn't. It doesn't: this bundle depends on `atoms/client` and Symfony
components only. If a future change to this package ever needs
`Atoms\Laravel\*` or `Illuminate\*`, that is a bug in the layering, not a gap
this bundle should paper over (see the layering test in
`tests/LayeringTest.php`, and `docs/conventions.md`).

## Setup

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
    http_client: null          # service id, or null to auto-detect / fall back to Guzzle
    methods_classes:
        - App\Atoms\GameRoom\Methods   # only needed for #[MethodsFor] overrides
```

Import the callback route (only if you kept the default `callback_path`;
otherwise define your own route pointing at
`Atoms\Symfony\Controller\CallbackController`), e.g.
`config/routes/atoms.php`:

```php
return static function (\Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator $routes): void {
    $routes->import(
        \dirname(__DIR__, 2) . '/vendor/atoms/symfony/src/Resources/config/routes.php',
    );
};
```

## What's wired

- `Atoms\Client\AtomsClient` — the RPC stub-proxy client, service id
  `atoms.client` (public; the `AtomsClient::class` service itself is private).
- The callback stack (`Ed25519Verifier`, `InMemoryNonceStore`,
  `MethodsResolver`, `CallbackKernel`) plus `Atoms\Symfony\Controller\CallbackController`,
  which converts Symfony `Request`/`Response` to/from PSR-7 by hand
  (`guzzlehttp/psr7`, no `psr/http-message-bridge` dependency).
- A PSR-18 client resolved, in order: the service id in `atoms.http_client`,
  else an app-defined `Psr\Http\Client\ClientInterface` service, else a
  `GuzzleHttp\Client` (clear exception if `guzzlehttp/guzzle` isn't
  installed). Resolved in a compiler pass so bundle registration order never
  matters.
- `Atoms\Client\Callback\QueueBridge`: `Atoms\Symfony\Messenger\MessengerQueueBridge`
  when `symfony/messenger` is installed and the app has a message bus
  service, wrapping dispatched `AtomJob`s as `AtomJobMessage` (normalized,
  JSON-safe constructor args only) and handling them back via
  `AtomJobHandler`; otherwise `NullQueueBridge`, which throws a clear
  "configure a QueueBridge" exception on first use.
- `atoms:deploy`, `atoms:rollback`, `atoms:list` — thin console wrappers that
  shell out to the real `atoms` binary (discovered via `vendor/bin/atoms`,
  then `$PATH`, then `packages/cli/bin/atoms`); registered only when
  `symfony/console` is present.

## What's deliberately not here

Methods classes are instantiated with `new $class()` (or resolved from a PSR
container if one is passed to `CallbackKernel` — this bundle doesn't wire
one). A real Symfony adapter would resolve Methods classes from the app's own
service container; that's out of scope for a Phase 1 layering test.
