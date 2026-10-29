# atoms/symfony

The supported Symfony bundle for Atoms wires `Atoms\Client\AtomsClient`, the
inbound callback stack (HMAC verification, Methods dispatch, AtomJob
reconstruction), a Messenger-backed queue bridge, and thin console wrappers
around the `atoms` binary — all on top of `atoms/client` alone.
`atoms/client` is deliberately framework-free, so
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
    endpoint: https://atoms.your-subdomain.workers.dev   # your own deployed Worker
    shared_secret: '%env(ATOMS_SHARED_SECRET)%'          # base64 of 32 random bytes, identical on the Worker
    # shared_secret_previous: '%env(ATOMS_SHARED_SECRET_PREVIOUS)%'   # add during a rotation overlap
    timeout: 10.0
    max_attempts: 3
    callback_path: /atoms/callback
    callback_timestamp_window: 300   # seconds of clock skew a callback's timestamp may deviate before rejection
    http_client: null                # service id, or null to auto-detect / fall back to Guzzle
    psr17_factory: null              # service id, or null to fall back to Guzzle (no auto-detection — see "What's wired")
    methods_classes:
        - App\Atoms\GameRoom\Methods   # only needed for #[MethodsFor] overrides
```

`shared_secret` is the root of the app ↔ Worker boundary: the same base64 of
32 random bytes the Worker holds as `ATOMS_SHARED_SECRET`. Keep it on your
hosts — requests carry a bearer derived from it with HKDF-SHA256, and inbound
callbacks are verified with a second derived key; `atoms token` prints the
bearer for hand-issued requests. To rotate, set `shared_secret_previous` to
the outgoing value on both sides: callbacks signed under either secret verify
while the overlap lasts, and outbound requests use the current one.

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

## Configure callback nonce storage

PHP-FPM and multiple application workers need a shared store. Choose one before
accepting callbacks. Store initialization runs after signature and timestamp
verification, so outbound-only applications do not need a nonce backend.
The bundle resolves `driver` and `redis_service` when compiling the container.
Rebuild the Symfony cache after changing either value, including changes supplied
through environment variables.

For Redis, use an existing native phpredis or Predis connection service:

```yaml
atoms:
    callback_nonce:
        driver: redis
        redis_service: app.redis
        namespace: my-app.production
```

For the database backend, install and enable DoctrineBundle and
DoctrineMigrationsBundle, then configure:

```yaml
atoms:
    callback_nonce:
        driver: database
        namespace: my-app.production
```

Atoms registers its table migration with the application runner. Run
`bin/console doctrine:migrations:migrate` before accepting callbacks. The store
uses the database selected by Doctrine Migrations through a separate autocommit
connection. Application rollbacks cannot remove its nonce records. SQLite needs
a persistent file shared by all workers on the host.
On MySQL and MariaDB, the table migration runs without a transaction because DDL
commits implicitly. Leave Doctrine Migrations `all_or_nothing` disabled for this
migration.

Schedule `bin/console atoms:nonces:prune` to delete expired database records.
Atoms excludes its table from ORM schema diffs and preserves existing schema
filters. Package migrations are registered while the database driver is selected;
switch back to it before rolling back the nonce-table migration after a move to
Redis.

`callback_nonce.retention_seconds` defaults to twice
`callback_timestamp_window`, plus one second and `callback_nonce.clock_allowance_seconds`.
The clock allowance defaults to 60 seconds. An explicit retention must cover
that interval. Keep clocks synchronized and prevent Redis from evicting or
clearing unexpired nonce keys. See the [callback guide](https://docs.atomsphp.dev/guides/callbacks/#store-callback-nonces)
for replay guarantees and failure responses.

## What's wired

- `Atoms\Client\AtomsClient` — the RPC stub-proxy client, service id
  `atoms.client` (public; the `AtomsClient::class` service itself is private).
- The callback stack includes `HmacVerifier`, the configured `NonceStore`,
  `MethodsResolver`, `CallbackKernel`, and
  `Atoms\Symfony\Controller\CallbackController`, which converts Symfony
  `Request`/`Response` to/from PSR-7 by hand against the plain
  `ServerRequestFactoryInterface`/`StreamFactoryInterface` (no
  `psr/http-message-bridge` dependency); routed automatically as described
  above.
- A PSR-17 factory (`ServerRequestFactoryInterface` + `StreamFactoryInterface`
  + `ResponseFactoryInterface` in one — `GuzzleHttp\Psr7\HttpFactory` and
  `Nyholm\Psr7\Factory\Psr17Factory` both qualify) resolved, in order: the
  service id in `psr17_factory`, else a bundled `GuzzleHttp\Psr7\HttpFactory`
  (clear exception if `guzzlehttp/psr7` isn't installed). No auto-detection:
  PSR-17 spans several factory interfaces, so the bundle never guesses which
  app service should serve all of them — name it explicitly in
  `psr17_factory` or accept Guzzle. Backs `CallbackKernel`,
  `CallbackController` and `AtomsClient` alike. Resolved in a compiler pass
  so bundle registration order never matters.
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
  own `Psr\Http\Client\ClientInterface` service and leave it null. Binding the
  `atoms.http_client` service id yourself outranks both, and is the way to
  override the resolution outright.
- **PSR-17 factory** — set `psr17_factory` to a service id implementing
  `ServerRequestFactoryInterface`, `StreamFactoryInterface` and
  `ResponseFactoryInterface`. As with the HTTP client, binding
  `atoms.psr17_factory` yourself takes precedence over the config key.
- **Queue bridge** — install `symfony/messenger` and register a message bus,
  or bind your own `Atoms\Client\Callback\QueueBridge` implementation to that
  service id directly.
- **Nonce store.** Set `callback_nonce.driver` to `redis` or `database` and
  choose a namespace for this application and environment. Without a store,
  signed callbacks return `503` with `ATOMS-E111`. Bind your own
  `Atoms\Client\Callback\NonceStore` to override the configured backend.
  Use `memory` only when the same store instance survives across callbacks.
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

## Development and support

This package is developed in the
[Atoms monorepo](https://github.com/AtomsPHP/atoms). Its standalone repository
is a read-only distribution mirror; report issues and send pull requests to
the monorepo. Full documentation is at
[docs.atomsphp.dev](https://docs.atomsphp.dev). Licensed under the
[MIT License](LICENSE).
