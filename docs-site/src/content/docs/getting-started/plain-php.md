---
title: Plain PHP quickstart
description: Use the tested plain-PHP recipe for a framework-free Atoms host.
---

Atoms does not hide a second plain-PHP integration behind these docs. The maintained and conformance-tested recipe is [`examples/plain-php/`](https://github.com/AtomsPHP/atoms/tree/main/examples/plain-php).

It builds two collaborators through `AtomsBootstrap::create()`:

- `AtomsClient`, used for outbound calls to your Worker;
- `CallbackKernel`, wrapped by `PlainPhpApp`, used for signed inbound `app()` and `dispatch()` callbacks.

## Install

```bash
composer require atoms/client:^0.4 guzzlehttp/psr7:^2.7
```

Supply a PSR-18 client, the PSR-17 factories, your endpoint, and the shared secret explicitly:

```php
$app = AtomsBootstrap::create(
    endpoint: getenv('ATOMS_ENDPOINT'),
    sharedSecret: getenv('ATOMS_SHARED_SECRET'),
    callbackPath: '/atoms/callback',
    http: $psr18Client,
    requestFactory: $factory,
    serverRequestFactory: $factory,
    responseFactory: $factory,
    streamFactory: $factory,
    queueBridge: $queueBridge,
);
```

`ATOMS_SHARED_SECRET` is base64 of 32 random bytes, required, and identical to the value configured on the Worker (`vendor/bin/atoms shared-secret:set --env production`). Every credential on the boundary — the outbound bearer, WebSocket ticket signing, and inbound callback verification — is derived from it. An optional `sharedSecretPrevious` argument widens callback and ticket acceptance during a rotation window.

Register the callback as a POST-only route in Slim, Mezzio, or another router:

```php
$router->post('/atoms/callback', fn ($request) => $app->handle($request));
```

For an application with no router, use the example’s `public/atoms-callback.php` front controller and `PlainPhpApp::handleGlobals()`.

Register Methods classes on `MethodsResolver`, and provide a real `QueueBridge` for production. Leaving the bridge unset deliberately produces [ATOMS-E103](/reference/errors/#atoms-e103) instead of losing a job silently.

The example’s README is the detailed recipe. Its bootstrap, front-controller handling, callback behavior, queue bridge, and example Methods class are exercised by the same adapter conformance cases as Laravel and Symfony.
