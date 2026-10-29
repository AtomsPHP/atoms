---
title: Adapter contract
description: What Laravel, Symfony, and plain-PHP hosts provide to atoms/client.
---

An adapter connects the framework-agnostic `atoms/client` library to a framework's configuration, container, router, queue, HTTP implementation, and logger. Everything on this page runs in your application, not inside an Atom.

The full contract lives in [`docs/adapters.md`](https://github.com/AtomsPHP/atoms/blob/main/docs/adapters.md).

## The shared contract

Every supported host supplies:

| Concern | What it does | Contract |
|---|---|---|
| **Outbound HTTP** | Sends the client's requests to the Atoms Worker | PSR-18 client and PSR-17 request/stream factories |
| **Callback queue** | Receives jobs an Atom dispatches | `Atoms\Client\Callback\QueueBridge` |
| **Configuration** | Gives the client its settings from the framework | `AtomsConfig::fromArray()` |
| **Callback route** | The endpoint the Worker calls back into | POST-only; passes the raw body and `X-Atoms-*` headers through |
| **Logging** | Receives logs from the client and callback kernel | Optional PSR-3 logger passed to both |
| **Methods construction** | Instantiates your Methods classes | Optional PSR-11 container, otherwise direct construction |
| **Ticket minting** | Issues the signed tickets browsers use to connect to an Atom | `Atoms\Client\Tickets\TicketIssuer`, built from the same `AtomsConfig` as the client |
| **Replay defense** | Rejects a callback delivered more than once | Replaceable `NonceStore`; instance-local default |

A [conformance suite](https://github.com/AtomsPHP/atoms/tree/main/tests/Integration/Adapters) checks this contract against the Laravel, Symfony, and plain-PHP adapters.

## Before you deploy

Callbacks require a persistent shared `NonceStore` when requests can reach
different processes or newly constructed stores. This includes PHP-FPM,
multiple web workers on one host, and recreated request containers.

The default `InMemoryNonceStore` remembers nonces only within that store
instance. A singleton container binding does not preserve its state across PHP
requests. See [callback replay protection](/guides/callbacks/#store-callback-nonces)
for atomicity, retention, and a shared-store example.

Configure a queue bridge so an Atom can dispatch jobs.

### Shared secret validation

`AtomsConfig` validates the [shared secret](/guides/secrets/#the-shared-secret) when constructed. A missing or malformed value raises [ATOMS-E105](/reference/errors/#atoms-e105).

| Host | Validation timing |
|---|---|
| Laravel | On first resolution of `AtomsConfig` or a service that needs it, such as `AtomsClient`. Boot and `artisan list` may succeed without checking the secret. |
| Symfony | When the container constructs `AtomsConfig`. Missing configuration or an unset `%env(...)%` may fail earlier. |
| Plain PHP | During `AtomsBootstrap::create()` or `CallbackKernelFactory::create()`. |

To check Laravel before deployment, save this script in the project root and run it with the deployment's environment and cached configuration:

```php title="check-atoms-config.php"
<?php

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$app->make(\Atoms\Client\AtomsConfig::class);
```

Run `php check-atoms-config.php`. This checks the local secret's format. Configure the application and Worker from the same secret value, then test authentication with a bearer-protected call or signed callback.

## Writing another adapter

Implement every row in the table, then run the conformance suite against your adapter. The Laravel adapter shows how: [`LaravelHost.php`](https://github.com/AtomsPHP/atoms/blob/main/tests/Integration/Adapters/Host/LaravelHost.php) and [`LaravelAdapterConformanceTest.php`](https://github.com/AtomsPHP/atoms/blob/main/tests/Integration/Adapters/LaravelAdapterConformanceTest.php).

- The callback route is a signed machine-to-machine endpoint, so don't apply browser or API middleware such as CSRF or sessions to it.
- Hand `CallbackKernel` the request body exactly as it arrived. Middleware that parses and re-encodes the JSON breaks the signature check.
- Keep framework types out of `atoms/core` and `atoms/client`.
