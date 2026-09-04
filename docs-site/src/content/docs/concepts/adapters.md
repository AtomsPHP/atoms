---
title: Adapter contract
description: What Laravel, Symfony, and plain-PHP hosts provide to atoms/client.
---

An adapter connects the framework-agnostic `atoms/client` library to a framework's configuration, container, router, queue, HTTP implementation, and logger.

The full contract lives in [`docs/adapters.md`](https://github.com/AtomsPHP/atoms/blob/main/docs/adapters.md).

Everything described below is app-side - describing code running _in your PHP app_ and how it interacts with the Atoms library.

## The shared contract

Every supported host supplies:

| Concern | Contract |
|---|---|
| **Outbound HTTP**<br>How the client sends requests to the Atoms Worker | PSR-18 client and PSR-17 request/stream factories |
| **Callback queue**<br>Where jobs dispatched by an Atom land in your app | `Atoms\Client\Callback\QueueBridge` |
| **Configuration**<br>How Atoms on the app side gets config values from the framework | `AtomsConfig::fromArray()` |
| **Callback route**<br>The endpoint the Worker calls back into your app | POST-only, passes the raw body and `X-Atoms-*` headers through |
| **Logging**<br>Where the client and callback kernel write their logs | Optional PSR-3 logger passed to both |
| **Methods construction**<br>How your Methods classes get instantiated | Optional PSR-11 container, otherwise direct construction |
| **Ticket minting**<br>Issues the signed tickets browsers use to connect to an Atom | `Atoms\Client\Tickets\TicketIssuer`, built from the same `AtomsConfig` as the client |
| **Replay defense**<br>Rejects a callback delivered more than once | Replaceable `NonceStore`; process-local default |

This contract is enforced by a [conformance suite](https://github.com/AtomsPHP/atoms/tree/main/tests/Integration/Adapters)  which runs against the Laravel, Symfony, and plain-PHP adapters. To run it against your own adapter, follow the Laravel adapter's example: [`LaravelHost.php`](https://github.com/AtomsPHP/atoms/blob/main/tests/Integration/Adapters/Host/LaravelHost.php) and [`LaravelAdapterConformanceTest.php`](https://github.com/AtomsPHP/atoms/blob/main/tests/Integration/Adapters/LaravelAdapterConformanceTest.php).

## Before you deploy

- The default nonce store is in-memory. For distributed apps, use a shared `NonceStore` implementation.
- Your shared secret must be strict base64 that decodes to exactly 32 bytes. The adapter checks this at boot and refuses to start otherwise ([ATOMS-E105](/reference/errors/#atoms-e105)).
- You must configure a queue bridge for an Atom to be able to dispatch jobs.

## Writing another adapter

Implement every row in the table and run the existing conformance cases against it. A few important notes:

- The callback route is a signed machine-to-machine endpoint, so don't apply standard browser or API middleware (like CSRF or session middleware) to it.
- Hand `CallbackKernel` the request body exactly as it arrived. Middleware that parses and re-encodes the JSON will break the signature check.
- Keep framework types out of `atoms/core` and `atoms/client`.
