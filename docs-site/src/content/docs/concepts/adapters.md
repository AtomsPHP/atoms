---
title: Adapter contract
description: What Laravel, Symfony, and plain-PHP hosts consistently provide to atoms/client.
---

An adapter connects framework-free `atoms/client` to a host application’s configuration, container, router, queue, HTTP implementation, and logger.

The normative contract is [`docs/adapters.md`](https://github.com/AtomsPHP/atoms/blob/main/docs/adapters.md). This page is an orientation, not a competing specification.

## The shared contract

Every supported host supplies:

| Concern | Contract |
|---|---|
| Outbound HTTP | PSR-18 client and PSR-17 request/stream factories |
| Callback queue | `Atoms\Client\Callback\QueueBridge` |
| Configuration | Values mapped without changing `AtomsConfig::fromArray()` semantics |
| Callback route | POST-only, byte-exact body and `X-Atoms-*` headers, no session or CSRF |
| Logging | Optional PSR-3 logger passed to client and callback kernel |
| Methods construction | Optional PSR-11 container, otherwise direct construction |
| Ticket minting | `Atoms\Client\Tickets\TicketIssuer`, built from the same `AtomsConfig` as the client |
| Replay defense | Replaceable `NonceStore`; process-local default |

The same adapter conformance case table exercises Laravel, Symfony, the tested plain-PHP example, and a bare callback kernel. Framework-specific plumbing may differ; observable behavior may not.

## Security-sensitive details

- `ATOMS_SHARED_SECRET` is required and validated on construction: trimmed of whitespace, strict base64, exactly 32 decoded bytes, or the adapter refuses to boot ([ATOMS-E105](/reference/errors/#atoms-e105)).
- The callback body must reach `CallbackKernel` byte-for-byte because it is signed.
- A process-local nonce store is insufficient when multiple application processes accept callbacks. Supply a shared implementation.
- A missing queue bridge fails with [ATOMS-E103](/reference/errors/#atoms-e103); a dispatched job is never silently ignored by the host adapter.
- An adapter that resolves `AtomsClient` must also resolve `TicketIssuer`: both are built from the same `AtomsConfig`, so supplying one without the other is a wiring bug rather than a missing capability.

If you write another adapter, implement every row and run it through the existing unmodified conformance cases. Do not introduce framework types into `atoms/core` or `atoms/client`.
