---
title: Callbacks
description: Configure signed app() calls and dispatch() jobs from an Atom back to your application.
---

An Atom can cross from Atom-side back into the host application in two ways:

- `$this->app()->method(...)` is synchronous reverse RPC into a paired Methods class.
- `$this->dispatch(new Job(...))` hands an `AtomJob` to the host’s queue bridge.

## Configure the channel

The Worker needs:

- `ATOMS_CALLBACK_URL`: the exact public POST endpoint mounted by your adapter
  (Laravel and Symfony default to `https://your-app.example/atoms/callback`);
- `ATOMS_CALLBACK_SIGNING_KEY`: base64 of a 32-byte Ed25519 seed.

The application receives the matching public key as `ATOMS_PLATFORM_PUBLIC_KEY`. Requests are signed over the timestamp, nonce, and exact body. The callback kernel verifies the signature, timestamp window, and nonce before invoking application code.

Set the operational signing secret through Wrangler. `atoms secrets:set` intentionally manages only secrets exposed to Atom code under the configured `ATOMS_CONFIG_` prefix.

`atoms.json`'s `callback_url` value is passed automatically to `atoms dev`.
For a deployed Worker, configure `ATOMS_CALLBACK_URL` on that Worker before
using `app()` or `dispatch()`; the deploy command does not infer or rewrite
Worker variables from application configuration.

## Synchronous `app()`

Pair an App-side class with an Atom:

```php
use Atoms\Attributes\MethodsFor;
use Atoms\AtomMethods;

#[MethodsFor(\App\Atoms\GameRoom::class)]
class Methods extends AtomMethods
{
    public function displayName(int $playerId): string
    {
        return User::findOrFail($playerId)->name;
    }
}
```

Inside `GameRoom`, `$this->app()->displayName($id)` waits for that response. Do not call it inside `$this->db()->transaction()`.

## Asynchronous `dispatch()`

`dispatch()` is at-most-once, unordered, and unretried in 0.1. Outside a transaction, delivery is initiated immediately and settled before the triggering Worker response completes. Inside a transaction, jobs are released only after commit and dropped on rollback.

Transport failures are logged and dropped because the frozen `dispatch(): void` ABI cannot report an asynchronous failure. Use idempotent jobs and do not treat this as a durable outbox.

Callback request and response sizes are configuration-bounded. Oversize payloads are rejected before they can grow guest memory without limit.
