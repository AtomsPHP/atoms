---
title: Client calls
description: Configure retries, idempotency keys, and tracing for an Atom call.
---

Your application calls an Atom through `AtomsClient::get()` or a framework
adapter. The Laravel example below uses the `Atoms` facade; Symfony and plain
PHP pass the same options to `AtomsClient::get()`.

## Per-call options

Pass `Atoms\Client\CallOptions` as the third argument to `get()` to configure one invocation:

```php
use App\Atoms\GameRoom;
use Atoms\Client\CallOptions;
use Atoms\Laravel\Facades\Atoms;

Atoms::get(GameRoom::class, $id, new CallOptions(retryTurnDeadline: true))
    ->recordResult($score);
```

- `retryTurnDeadline` (default `false`) treats a `turn_deadline_exceeded` failure as retryable. Leave it off unless the method is idempotent: a turn that ran out of time may already have committed its writes.
- `idempotencyKey` reuses a specific `Idempotency-Key` header value instead of a fresh random one per call; it must be unique per logical call.
- `traceparent` sets the W3C `traceparent` header for this call only.

