---
title: Callbacks
description: Configure signed app() calls and dispatch() jobs from an Atom back to your application.
---

An Atom can cross from Atom-side back into the host application in two ways:

- `$this->app()->method(...)` is synchronous reverse RPC into a method defined in an Atom's `Methods` class.
- `$this->dispatch(Job::class, [...])` hands an [`AtomJob`](/guides/jobs/) to the host's queue bridge.

## Configure the channel

For these callbacks to work, you must configure a shared secret and a callback
URL. Each lives in a specific place:

- `callback_url.<environment>` in `atoms.json` declares the callback URL for a
  deploy target. The entry is optional; an empty or absent entry means the
  Worker has no callback URL and `app()`/`dispatch()` are unavailable.
- `ATOMS_CALLBACK_URL` is the variable the selected Worker receives. It is not
  a secret and is derived from the file declaration by the CLI.
- `ATOMS_SHARED_SECRET` is configured on both sides: as a secret on the Worker,
  and in your application's `.env` (or equivalent). See [Secrets and
  authentication](/guides/secrets/) for setting it.

Every callback POST is signed with a key derived from that secret, and your
adapter verifies the signature before your Methods class or job runs. See the
[adapter contract](/concepts/adapters/) for what a host must provide.

## Callback URL

Declare the URL in the top-level `callback_url` map in `atoms.json`:

```json
{
    "callback_url": {
        "production": "https://example.com/atoms/callback",
        "staging": "${STAGING_CALLBACK_URL}"
    }
}
```

The whole value may be a `${ENV_VAR}` reference. A referenced variable that is
unset or empty is an [ATOMS-E070](/reference/errors/#atoms-e070) configuration
error. A literal empty string means no callback is declared. The Worker
requires HTTPS, except for HTTP loopback URLs used in local development, such
as `http://127.0.0.1:8000/atoms/callback`.

For deployment, the selected file entry is authoritative. `atoms deploy` has
no `--callback-url` option. If `ATOMS_CALLBACK_URL` is present in the deploy
process, it must match the literal or resolved file value; it is never a
fallback. Supplying it without a file entry, or supplying a different value,
raises ATOMS-E070. With no file entry and no ambient value, deploy warns and
forwards no callback variable.

Local development has a separate rule. `atoms dev --callback-url ...` and the
ambient `ATOMS_CALLBACK_URL` are local sources; if both are present they must
match. If neither is present, `atoms dev` uses the selected environment's file
value when one exists. A local source may differ from a committed production
value, which supports a developer tunnel.

The callback URL is for reverse calls from the Worker. Configure the
application's `ATOMS_ENDPOINT` separately with the Worker URL used for normal
Atom RPC. `ATOMS_ENVIRONMENT` may label the application environment in logs;
it does not select an `atoms.json` environment.

## Synchronous `app()`

`$this->app()->method(...)` calls into the Atom's [Methods class](/guides/methods/) and waits for the response; the Atom is blocked for the whole round trip. Do not call it inside `$this->db()->transaction()`.

## Asynchronous `dispatch()`

`dispatch()` hands a job to your application's queue and returns immediately. See [Jobs](/guides/jobs/) for writing one and the delivery guarantees.

Callback request and response size limits are configurable via Workers environment variables.
