---
title: Callbacks
description: Configure signed app() calls and dispatch() jobs from an Atom back to your application.
---

An Atom can cross from Atom-side back into the host application in two ways:

- `$this->app()->method(...)` is synchronous reverse RPC into a method defined in an Atom's `Methods` class.
- `$this->dispatch(Job::class, [...])` hands an [`AtomJob`](/guides/jobs/) to the host's queue bridge.

## Configure the channel

For these callbacks to work, you must configure a shared secret and a callback URL. Each lives in a specific place:

- `ATOMS_CALLBACK_URL` is an environment variable **on the Worker**. It tells
  the Worker where to POST. See
  [Callback URL](#callback-url).
- `ATOMS_SHARED_SECRET` is base64 of 32 random bytes, configured **on both
  sides**: as a secret on the Worker (set with `atoms shared-secret:set`,
  below), and in your application's `.env` (or
  equivalent).

Every callback POST is signed with a key derived from that secret, and your
adapter verifies the signature before your Methods class or job runs. See
[adapter contract](/concepts/adapters/) and
[`docs/shared-secret.md`](https://github.com/AtomsPHP/atoms/blob/main/docs/shared-secret.md)
for more details.

Set the secret on the Worker with `atoms shared-secret:set`, which reads the
value from stdin so it never appears in a command line or process listing:

```bash
openssl rand -base64 32 | vendor/bin/atoms shared-secret:set --env production
```

This is the only way to define `ATOMS_SHARED_SECRET` on the Worker; `atoms secrets:set`
refuses it. See
[Deploy](/guides/deploy/#configure-callbacks-and-application-secrets) for
setting the secret from CI.

## Callback URL

Locally, `atoms dev` sets `ATOMS_CALLBACK_URL` for you from `atoms.json`'s
`callback_url.<env>`; you can override it with the `--callback-url` flag in the CLI.

For a real Worker, set the variable with Wrangler — `atoms deploy` ignores
`callback_url`, so an `atoms.json` entry for a deployed environment has no
effect.

## Synchronous `app()`

`$this->app()->method(...)` calls into the Atom's [Methods class](/guides/methods/) and waits for the response; the Atom is blocked for the whole round trip. Do not call it inside `$this->db()->transaction()`.

## Asynchronous `dispatch()`

`dispatch()` hands a job to your application's queue and returns immediately. See [Jobs](/guides/jobs/) for writing one and the delivery guarantees.

Callback request and response size limits are configurable via Workers environment variables.
