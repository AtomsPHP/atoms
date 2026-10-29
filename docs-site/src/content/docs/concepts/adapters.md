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
| **Replay defense** | Rejects a callback delivered more than once | Replaceable `NonceStore`; process-local default |

A [conformance suite](https://github.com/AtomsPHP/atoms/tree/main/tests/Integration/Adapters) checks this contract against the Laravel, Symfony, and plain-PHP adapters.

## Before you deploy

- The default nonce store is in-memory. For distributed apps, use a shared `NonceStore` implementation.
- Configure a queue bridge so an Atom can dispatch jobs.

### Shared secret validation

Constructing `AtomsConfig` validates the [shared secret](/guides/secrets/#the-shared-secret); a missing or malformed value raises [ATOMS-E105](/reference/errors/#atoms-e105). When that happens depends on the host:

| Host | Validation timing |
|---|---|
| Laravel | `AtomsConfig` is a lazy singleton. Resolving it, `AtomsClient`, `CallbackKernel`, or `TicketIssuer` validates the secret. Framework boot, callback route registration, and `artisan list` can work until an Atoms service is needed. |
| Symfony | Resolving the service that creates `AtomsConfig` validates the secret's format. The configuration schema rejects an absent or empty literal `shared_secret` earlier; an unset `%env(...)%` can instead raise a Symfony environment error. |
| Plain PHP | The example validates during the explicit `AtomsBootstrap::create()` call. The bare `CallbackKernelFactory::create()` validates immediately. |

To check Laravel configuration before deployment, run this script from the Laravel project root using the deployment's actual environment and cached configuration:

```php title="check-atoms-config.php"
<?php

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$app->make(\Atoms\Client\AtomsConfig::class);
```

Save it in the project root and run `php check-atoms-config.php`. An uncaught validation error makes the command fail. This local check validates the configured secret's format, sends no request, and cannot prove that the Worker has a matching secret.

After deployment, make an application-to-Worker authenticated call with bearer authentication required. If bearer authentication is disabled, a successful call does not prove secret agreement; use a signed callback or ticket integration check instead. During rotation, acceptance of a previous key proves the credentials are compatible, not that the current values are identical. To ensure an exact current match, configure both sides from the same secret-manager value.

## Writing another adapter

Implement every row in the table, then run the conformance suite against your adapter. The Laravel adapter shows how: [`LaravelHost.php`](https://github.com/AtomsPHP/atoms/blob/main/tests/Integration/Adapters/Host/LaravelHost.php) and [`LaravelAdapterConformanceTest.php`](https://github.com/AtomsPHP/atoms/blob/main/tests/Integration/Adapters/LaravelAdapterConformanceTest.php).

- The callback route is a signed machine-to-machine endpoint, so don't apply browser or API middleware such as CSRF or sessions to it.
- Hand `CallbackKernel` the request body exactly as it arrived. Middleware that parses and re-encodes the JSON breaks the signature check.
- Keep framework types out of `atoms/core` and `atoms/client`.
