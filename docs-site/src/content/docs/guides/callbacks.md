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
- `ATOMS_SHARED_SECRET` is configured **on both sides**: as a secret on the
  Worker, and in your application's `.env` (or equivalent). See
  [Secrets and authentication](/guides/secrets/) for setting it.

Every callback POST is signed with a key derived from that secret, and your
adapter verifies the signature before your Methods class or job runs. See the
[adapter contract](/concepts/adapters/) for what a host must provide.

## Callback URL

Set the application's callback URL for each environment under
[`callback_url` in `atoms.json`](/guides/configuration/#atomsjson-keys).

`atoms dev` and `atoms deploy` choose the URL in this order:

1. `--callback-url`;
2. `ATOMS_CALLBACK_URL` in the process environment;
3. `callback_url.<env>` in `atoms.json`.

For example, override the production URL for a deployment:

```bash
vendor/bin/atoms deploy --env production --callback-url https://example.com/atoms/callback
```

The selected URL is passed to Wrangler as `ATOMS_CALLBACK_URL`, overriding
the Worker's configured value. If all three sources are empty, Wrangler uses
the Worker's configuration.

## Synchronous `app()`

`$this->app()->method(...)` calls into the Atom's [Methods class](/guides/methods/) and waits for the response; the Atom is blocked for the whole round trip. Do not call it inside `$this->db()->transaction()`.

## Asynchronous `dispatch()`

`dispatch()` hands a job to your application's queue and returns immediately. See [Jobs](/guides/jobs/) for writing one and the delivery guarantees.

Callback request and response size limits are configurable via Workers environment variables.
