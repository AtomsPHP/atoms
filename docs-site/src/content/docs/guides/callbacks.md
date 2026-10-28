---
title: Callbacks
description: Configure signed app() calls and dispatch() jobs from an Atom back to your application.
---

An Atom can cross from Atom-side back into the host application in two ways:

- `$this->app()->method(...)` is synchronous reverse RPC into a method defined in an Atom's `Methods` class.
- `$this->dispatch(Job::class, [...])` hands an [`AtomJob`](/guides/jobs/) to the host's queue bridge.

## Configure the channel

Callbacks need a callback URL and the shared secret.

Set the callback URL on each environment in `atoms.json`. This is where the
Worker sends callbacks to your application:

```json
{
    "environments": {
        "production": {
            "worker_name": "my-app",
            "callback_url": "https://example.com/atoms/callback"
        }
    }
}
```

The URL must use HTTPS, except for local development on `localhost`. To use a
different URL on your own machine, such as a tunnel, set `ATOMS_CALLBACK_URL`
in `.env.atoms.<environment>`. [Environments](/guides/environments/#where-values-come-from)
lists every place the URL can come from. Your application's `ATOMS_ENDPOINT`
is a separate setting that points the other way, from your application to the
Worker.

Configure the shared secret on both your application and the Worker, as
[Secrets and authentication](/guides/secrets/) describes. Every callback is
signed with a key derived from it, and your adapter verifies the signature
before your Methods class or job runs. See the [adapter
contract](/concepts/adapters/) for what a host must provide.

## Synchronous `app()`

`$this->app()->method(...)` calls into the Atom's [Methods class](/guides/methods/) and waits for the response; the Atom is blocked for the whole round trip. Do not call it inside `$this->db()->transaction()`.

## Asynchronous `dispatch()`

`dispatch()` hands a job to your application's queue and returns immediately. See [Jobs](/guides/jobs/) for writing one and the delivery guarantees.

Callback request and response size limits are configurable via Workers environment variables.
