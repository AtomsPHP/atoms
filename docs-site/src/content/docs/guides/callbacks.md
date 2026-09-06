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
  a secret; the CLI derives it from the file declaration, or from
  `--callback-url` when that is passed.
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

The whole value may be a `${ENV_VAR}` reference. A literal empty or
whitespace-only string means no callback is declared. The Worker requires
HTTPS, except for HTTP loopback URLs used in local development, such as
`http://127.0.0.1:8000/atoms/callback`.

**The file entry is the committed default, not the last word.** Both `deploy`
and `dev` resolve the callback URL in one order:

1. `--callback-url`, available on `deploy` and `dev` alike.
2. `ATOMS_CALLBACK_URL` in the process environment, on `deploy` and `dev` alike
   — which is how a developer tunnel or a CI-supplied host is used without
   editing the committed file.
3. The selected `callback_url.<env>` entry in `atoms.json`.

The nearer source wins, silently. Nothing is compared, and no combination of
sources is an error. With no source at all, deploy warns and forwards no
callback variable.

A `${ENV_VAR}` reference in the file is read only when steps 1 and 2 supplied
nothing. On `deploy`, a referenced variable that is unset or empty is then an
[ATOMS-E070](/reference/errors/#atoms-e070) configuration error; `atoms dev`
treats it as no callback and warns, because the variable may belong to CI. That
is the only way the two commands differ here.

The callback URL is for reverse calls from the Worker. Configure the
application's `ATOMS_ENDPOINT` separately with the Worker URL used for normal
Atom RPC. `ATOMS_ENVIRONMENT` may label the application environment in logs;
it does not select an `atoms.json` environment.

## Synchronous `app()`

`$this->app()->method(...)` calls into the Atom's [Methods class](/guides/methods/) and waits for the response; the Atom is blocked for the whole round trip. Do not call it inside `$this->db()->transaction()`.

## Asynchronous `dispatch()`

`dispatch()` hands a job to your application's queue and returns immediately. See [Jobs](/guides/jobs/) for writing one and the delivery guarantees.

Callback request and response size limits are configurable via Workers environment variables.
