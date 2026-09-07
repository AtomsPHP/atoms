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

- `environments.<name>.callback_url` in `atoms.json` declares the committed default
  callback URL for a deploy target. The entry is optional, and an empty or
  absent one does **not** mean callbacks are unavailable — it only means the
  file supplies nothing, leaving the three nearer sources below to answer.
- `ATOMS_CALLBACK_URL` in `.env.atoms.<environment>` beside `atoms.json`
  supplies the same value without editing anything committed — the file is
  gitignored, and read only for the target you named.
- `ATOMS_CALLBACK_URL` in the environment the command was started with — a CI
  job variable, say — outranks both.
- `--callback-url`, on `deploy` and `dev`, outranks all three.

The variable the selected Worker receives is also called `ATOMS_CALLBACK_URL`.
It is not a secret; the CLI forwards whichever of the four sources answered
first (see [Callback URL](#callback-url) below), and prints which one that was.
Callbacks are unavailable only when *no* source supplies a URL.
- `ATOMS_SHARED_SECRET` is configured on both sides: as a secret on the Worker,
  and in your application's `.env` (or equivalent). See [Secrets and
  authentication](/guides/secrets/) for setting it.

Every callback POST is signed with a key derived from that secret, and your
adapter verifies the signature before your Methods class or job runs. See the
[adapter contract](/concepts/adapters/) for what a host must provide.

## Callback URL

Declare the URL on the environment it belongs to, beside its `worker_name`:

```json
{
    "environments": {
        "production": {
            "worker_name": "my-app",
            "callback_url": "https://example.com/atoms/callback"
        },
        "staging": {
            "worker_name": "my-app-staging",
            "callback_url": "${STAGING_CALLBACK_URL}"
        }
    }
}
```

The whole value may be a `${ENV_VAR}` reference, resolved against the same
environment sources as everything else and against nothing else. A literal
empty or whitespace-only string declares nothing here — as it does from the
flag and from `ATOMS_CALLBACK_URL`, which normalise the same way, so a blank
value never wins over a real one from a further source. The Worker requires
HTTPS, except for HTTP loopback URLs used in local development, such as
`http://127.0.0.1:8000/atoms/callback`.

**The file entry is the committed default, not the last word.** Both `deploy`
and `dev` resolve the callback URL in one order:

1. `--callback-url`, available on `deploy` and `dev` alike.
2. `ATOMS_CALLBACK_URL` in the environment the command was started with — a CI
   job variable, or a value your shell exported.
3. `ATOMS_CALLBACK_URL` in `.env.atoms.<env>` beside `atoms.json` — where a
   developer tunnel or a local port belongs, since the file is gitignored and
   read only for the target you named.
4. The selected environment's `callback_url` in `atoms.json`.

The nearer source wins, silently. Nothing is compared, and no combination of
sources is an error. With no source at all, deploy warns and forwards no
callback variable. Because the winner is silent, `deploy` and `dev` print which
source supplied the URL before they use it.

The `atoms.json` entry is read only when steps 1 to 3 supplied nothing — so
nothing in it can fail a command that a nearer source already answered. That
holds for a malformed entry too: a value containing `${` that is not a
whole-value reference, such as `"https://${HOST}/callback"`, is
[ATOMS-E070](/reference/errors/#atoms-e070) only when the file wins, and is
never inspected when a nearer source supplied a value.

When the file does win, a well-formed reference to a variable that is unset or
empty is [ATOMS-E070](/reference/errors/#atoms-e070) on `deploy`; `atoms dev`
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
