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

After saving the shared secret and loading it into your shell environment,
set it on the deployed Worker with `atoms shared-secret:set`, which reads the
value from stdin so it never appears in a command line or process listing:

```bash
printf '%s' "$ATOMS_SHARED_SECRET" | vendor/bin/atoms shared-secret:set --env production
```

Use this dedicated CLI command for `ATOMS_SHARED_SECRET`; `atoms secrets:set`
refuses it. See
[Deploy](/guides/deploy/#configure-callbacks-and-application-secrets) for
setting the secret from CI.

## Callback URL

Locally, `atoms dev` sets `ATOMS_CALLBACK_URL` for you from `atoms.json`'s
`callback_url.<env>`; you can override it with the `--callback-url` flag in the CLI.

For a deployed Worker, add `ATOMS_CALLBACK_URL` to `vars` in
`atoms-worker/wrangler.jsonc` if all deployments use the same URL. For a
different URL per deployment, leave it out of `vars` and set it separately
on each Worker with the installed Wrangler:

```bash
cd atoms-worker
printf '%s' "$ATOMS_CALLBACK_URL" | \
  ./node_modules/.bin/wrangler secret put ATOMS_CALLBACK_URL --name my-app-production
cd ..
```

Use the `worker_name` from that environment's `atoms.json` entry. The Worker
must already be deployed. `atoms deploy --env production` selects an Atoms
environment, not a Wrangler `[env]` section; `callback_url` in `atoms.json`
only configures local development.

## Synchronous `app()`

`$this->app()->method(...)` calls into the Atom's [Methods class](/guides/methods/) and waits for the response; the Atom is blocked for the whole round trip. Do not call it inside `$this->db()->transaction()`.

## Asynchronous `dispatch()`

`dispatch()` hands a job to your application's queue and returns immediately. See [Jobs](/guides/jobs/) for writing one and the delivery guarantees.

Callback request and response size limits are configurable via Workers environment variables.
