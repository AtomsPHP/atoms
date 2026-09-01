---
title: Callbacks
description: Configure signed app() calls and dispatch() jobs from an Atom back to your application.
---

An Atom can cross from Atom-side back into the host application in two ways:

- `$this->app()->method(...)` is synchronous reverse RPC into a paired Methods class.
- `$this->dispatch(new Job(...))` hands an `AtomJob` to the host’s queue bridge.

## Configure the channel

The Worker needs one endpoint and the one root secret every boundary
credential is derived from:

- `ATOMS_CALLBACK_URL`: the exact public POST endpoint mounted by your adapter
  (Laravel and Symfony default to `https://your-app.example/atoms/callback`);
- `ATOMS_SHARED_SECRET`: base64 of 32 random bytes, configured identically on
  the Worker and the application.

Both sides derive the signing key from that one secret. The Worker derives
a 32-byte HMAC-SHA256 key from `ATOMS_SHARED_SECRET` with HKDF-SHA256
(`info` string `atoms/callback/v1`), and your application derives the same
key with the same secret, in PHP, with `hash_hkdf()`. The signature never
leaves the Worker as anything but a tag over the request; the secret itself
is never transmitted.

Each callback POST is signed over `"v1\n{unix_timestamp}\n{nonce}\n"` followed
by the exact request body, and carries `x-atoms-signature` (base64 of the
32-byte HMAC tag), `x-atoms-timestamp`, `x-atoms-nonce`, and `x-atoms-kind`
(`methods` for `app()`, `job` for `dispatch()`). `Atoms\Client\Callback\CallbackKernel`
verifies the signature with `hash_equals()`, checks the timestamp window, and
rejects a reused nonce, all before your Methods class or job runs. Every callback carries that
signature, and one that fails verification is rejected with
[ATOMS-E064](/reference/errors/#atoms-e064) whether or not the deployment is
otherwise using bearer auth.

`ATOMS_SHARED_SECRET` is mandatory for the Worker as a whole, not only for
callbacks: without it, every route except `GET /healthz` answers
`misconfigured`. If the secret is present but `ATOMS_CALLBACK_URL` is unset,
`app()`/`dispatch()` fail with [ATOMS-E080](/reference/errors/#atoms-e080)
(`callback_not_configured`); if the URL is set but the secret is missing or
malformed, they fail with [ATOMS-E081](/reference/errors/#atoms-e081)
(`callback_unsigned`). `ATOMS_BEARER_AUTH` — the posture that governs the
`Authorization` header on inbound requests to the Worker — has no bearing on
this: callback signing runs whenever a usable secret is configured, in
either posture.

Set the secret on the Worker with `atoms shared-secret:set`, which reads the
value from stdin so it never appears in a command line or process listing:

```bash
openssl rand -base64 32 | vendor/bin/atoms shared-secret:set --env production
```

This is the only CLI path to `ATOMS_SHARED_SECRET`: `atoms secrets:set`
intentionally refuses it ([ATOMS-E077](/reference/errors/#atoms-e077)),
because that command manages only the `ATOMS_CONFIG_`-prefixed secrets Atom
code can read through `$this->config()`, and the boundary root must never
live in that namespace. Put the identical value in your application's own
secret store under the same name. To curl the Worker by hand without ever
pasting the secret into a header, print the derived bearer instead:

```bash
curl -H "Authorization: Bearer $(vendor/bin/atoms token --env production)" \
  https://your-worker.example.workers.dev/healthz
```

See [Deploy](/guides/deploy/#configure-callbacks-and-application-secrets) for
setting the secret from CI, and [`docs/shared-secret.md`](https://github.com/AtomsPHP/atoms/blob/main/docs/shared-secret.md)
in the monorepo for the full derivation and rotation contract.

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

`dispatch()` is at-most-once, unordered, and unretried in 0.4. Outside a transaction, delivery is initiated immediately and settled before the triggering Worker response completes. Inside a transaction, jobs are released only after commit and dropped on rollback.

Transport failures are logged and dropped because the frozen `dispatch(): void` ABI cannot report an asynchronous failure. Use idempotent jobs and do not treat this as a durable outbox.

Callback request and response sizes are configuration-bounded. Oversize payloads are rejected before they can grow guest memory without limit.
