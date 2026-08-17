# Atoms in plain PHP

A recipe for wiring Atoms into an application that is neither Laravel nor
Symfony — a Slim or Mezzio app, or no micro-framework at all. `atoms/laravel`
and `atoms/symfony` do this wiring for you inside their own container; here,
you do it yourself, once, explicitly.

## What you get

Two collaborators, both built by one call to `AtomsBootstrap::create()`:

- an `Atoms\Client\AtomsClient` — the outbound RPC client your Atoms call
  through (`$app->client()->get(GameRoom::class, $id)->greet('p-1')`);
- an `Atoms\Client\Callback\CallbackKernel`, wrapped in
  `Atoms\Examples\PlainPhp\PlainPhpApp`, which answers the platform's inbound
  callback — `$this->app()` calls landing back in your monolith, and
  `$this->dispatch()` jobs handed to your queue.

## Install

```json
{
    "require": {
        "php": "^8.3",
        "atoms/client": "^0.1",
        "guzzlehttp/psr7": "^2.7"
    }
}
```

(`composer.json` in this directory is that same shape, kept here for
reference — it is illustrative only, not installed by the monorepo's own root
`composer install`.) Any PSR-18/17 implementation works; this recipe uses
`guzzlehttp/psr7`'s `GuzzleHttp\Psr7\HttpFactory`, which alone covers every
PSR-17 factory role `AtomsBootstrap::create()` asks for.

## Wire

```php
use Atoms\Examples\PlainPhp\AtomsBootstrap;
use Atoms\Examples\PlainPhp\ArrayQueueBridge;
use GuzzleHttp\Psr7\HttpFactory;

$factory = new HttpFactory();

$app = AtomsBootstrap::create(
    endpoint: 'https://atoms.your-subdomain.workers.dev',
    sharedSecret: getenv('ATOMS_SHARED_SECRET'),
    callbackPath: '/atoms/callback',
    http: $yourPsr18Client,
    requestFactory: $factory,
    serverRequestFactory: $factory,
    responseFactory: $factory,
    streamFactory: $factory,
    queueBridge: new ArrayQueueBridge(),               // see "Supply a real queue bridge" below
    sharedSecretPrevious: getenv('ATOMS_SHARED_SECRET_PREVIOUS') ?: null, // see "The shared secret" below
);
```

Every argument is a parameter, not a discovery — there is no container for
`AtomsBootstrap` to reach into. That is deliberate: it is the one thing a
plain-PHP host cannot get for free from a framework adapter, so the example
makes the wiring explicit instead of hiding it behind autodetection.

## The shared secret

`ATOMS_SHARED_SECRET` is one value, configured identically here and on the
Worker: 32 random bytes, base64-encoded (`openssl rand -base64 32`). It is
never sent over the wire in either direction. `AtomsClient` derives its
outbound bearer from it (HKDF-SHA256), and `CallbackKernel` derives its
inbound HMAC verification key from it the same way — both sides compute,
never transmit, the values that actually cross the network.

To `curl` the Worker directly for testing, don't paste the secret into an
`Authorization` header — run `atoms token` (from `atoms/cli`) to print the
derived bearer instead:

```sh
curl -H "Authorization: Bearer $(atoms token)" https://atoms.your-subdomain.workers.dev/invoke/...
```

`ATOMS_SHARED_SECRET_PREVIOUS` supports zero-downtime rotation: set it to the
outgoing secret while `ATOMS_SHARED_SECRET` holds the new one, deploy both
sides, and this host verifies inbound callbacks signed under either secret
until every instance on both sides holds the new one — see
`docs/shared-secret.md` for the full runbook.

## Mount

**Slim, Mezzio, or any PSR-15-shaped router** — route the one callback path to
a handler that calls `$app->handle()` with the ServerRequest your router
already built:

```php
$router->post('/atoms/callback', fn (ServerRequestInterface $request): ResponseInterface
    => $app->handle($request));
```

**No framework at all** — point your webserver at
`public/atoms-callback.php`, a front controller that builds the request from
PHP's superglobals and emits the response itself:

```php
$response = $app->handleGlobals($_SERVER, file_get_contents('php://input'));

http_response_code($response->getStatusCode());
foreach ($response->getHeaders() as $name => $values) {
    foreach ($values as $value) {
        header("{$name}: {$value}", false);
    }
}
echo $response->getBody();
```

`handle()` rejects anything that is not a `POST` to the configured
`callbackPath` (405 / 404) before the request ever reaches the kernel; the
kernel then does the real work — HMAC signature check, replay check, Methods
dispatch or job enqueue — described in `docs/conventions.md` §Callback
signing.

## Register your Methods classes

The platform's callback payload names the Atom by its class **basename**
(`"GameRoom"`, not the full FQCN) — see `src/Atoms/GameRoom/Methods.php` in
this directory, which carries `#[MethodsFor(GameRoom::class)]` for exactly
this reason. Build a `MethodsResolver` before you build `$app`, register every
Methods class on it, and pass it in — `AtomsBootstrap::create(...,
resolver: $resolver)`:

```php
$resolver = new \Atoms\Client\Callback\MethodsResolver();
$resolver->registerMethodsClass(\App\Atoms\GameRoom\Methods::class); // one line per Methods class, reads its #[MethodsFor]
```

`registerTypeMap(['GameRoom' => \App\Atoms\GameRoom::class])` is the
lower-level escape hatch for a Methods class with no `#[MethodsFor]`
attribute, or a wire type whose basename does not match the Atom class's own.

If you already run `atoms build`, `registerManifest()` fills that same map
from the manifest instead of a line per Atom — every Atom the manifest
declares, in one call:

```php
$manifest = (new \Atoms\Client\Manifest\ManifestLoader())->load('.atoms/build/manifest.json');
$resolver->registerManifest($manifest);
```

It only maps wire types to Atom classes; a Methods class that is not
convention-named still needs its `#[MethodsFor]` (or a `map()` entry).

## Supply a real queue bridge

`ArrayQueueBridge` (`src/ArrayQueueBridge.php`) is a demo: it appends
dispatched jobs to an in-memory list so the recipe (and its tests) can assert
on them. It is not a queue. Point `queueBridge:` at your own
`Atoms\Client\Callback\QueueBridge` implementation that hands the job to
whatever your app already uses to queue work.

Leave `queueBridge` unset and `AtomsBootstrap` wires
`Atoms\Client\Callback\NullQueueBridge` instead, which fails loudly
(`ATOMS-E103`) the first time an Atom dispatches a job — a clear error instead
of a job silently vanishing.

The replay-check `NonceStore` and its timestamp window are injectable the
same way: pass `nonceStore:` a `Atoms\Client\Callback\NonceStore` of your own
(e.g. one backed by a shared cache, so replay checks survive across processes)
and/or `timestampWindow:` a different tolerance in seconds to
`AtomsBootstrap::create()`; leave either unset and you get the in-process
`InMemoryNonceStore` and a 300-second window.

## This recipe cannot rot

Everything under `src/` here is exercised by the repo's adapter conformance
suite (`tests/Integration/Adapters`) through `PlainPhpApp::handleGlobals()`
and `handle()` — the same code paths this README walks through.
`PlainPhpAdapterConformanceTest` drives `AtomsBootstrap`/`PlainPhpApp` that
way against the suite's own case table (substituting suite-owned doubles for
the queue bridge and Methods class so it can share that table across every
host); `PlainPhpExampleFidelityTest` drives the SAME two methods against this
directory's own `ArrayQueueBridge` and `GameRoom` Methods class specifically,
with no substitution. If this example ever drifts from what `atoms/client`
actually expects, the suite fails, not silently bit-rots.

The one part of this directory outside that coverage is the final four lines
of `public/atoms-callback.php` — the SAPI emit (`http_response_code()`, the
`header()` loop, `echo`) — because those talk to a running PHP SAPI, not to
`atoms/client`.
