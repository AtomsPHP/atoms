---
title: Secrets and authentication
description: Set the shared secret that authenticates the app and Worker to each other, and the application secrets an Atom reads.
---

Two different kinds of secret reach a Worker, and they are managed by different
commands:

- **`ATOMS_SHARED_SECRET`** authenticates your application and the Worker to
  each other. There is exactly one, both sides hold the same value, and Atom
  code can never read it.
- **Application secrets** — an API key your Atom needs — are set with
  `secrets:set` and read back through `$this->config()`.

## The shared secret

`ATOMS_SHARED_SECRET` is a base64 value that decodes to 32 random bytes.
Generate one, save it in your secret manager, and configure that same value as
`ATOMS_SHARED_SECRET` in your application and CI environment. Then supply it to
the Worker:

```bash
openssl rand -base64 32
```

```bash
printf '%s' "$ATOMS_SHARED_SECRET" | \
  vendor/bin/atoms shared-secret:set --env production
```

Run this **after the first deployment**, because the Worker must exist before a
secret can be set on it. Until then, every route but `/healthz` returns a
configuration error — a Worker with no valid secret fails closed rather than
serving unauthenticated traffic.

:::caution
`shared-secret:set` leaves an existing secret unchanged unless you pass
`--force`. A pipeline that re-runs it with a *changed* value silently does
nothing. See [Rotate the shared secret](#rotate-the-shared-secret).
:::

The value is never transmitted. Both sides derive purpose-specific keys from it:
the bearer token your application sends, the signature on callbacks the Worker
POSTs back, and the short-lived tickets browsers use to open WebSockets. For the
derivations and test vectors, see
[`docs/shared-secret.md`](https://github.com/AtomsPHP/atoms/blob/main/docs/shared-secret.md),
which is the normative record.

### Bearer auth postures

The Worker variable `ATOMS_BEARER_AUTH` controls whether the Worker checks the
`Authorization` bearer your application sends automatically. Leave it at the
default, `required`; set it to `disabled` only when an authenticating proxy such
as Cloudflare Access already sits in front of the Worker.

`ATOMS_SHARED_SECRET` stays mandatory in either posture, and browser connections
are unaffected — they authenticate with a short-lived
[ticket](/guides/websockets-timers/) either way.

### Calling a protected route by hand

`atoms token` prints the bearer derived from the shared secret, so you never
have to paste the secret itself into a header. This example calls the `join`
method from the [overview](/):

```bash
curl -H "Authorization: Bearer $(vendor/bin/atoms token --env production)" \
  -H 'Content-Type: application/json' \
  --data '{"args":["ada"]}' \
  https://your-worker.example.workers.dev/invoke/GameRoom/room-42/join
```

Set `ATOMS_SHARED_SECRET` in your shell to the production value before running
this.

## Application secrets

Use `secrets:set` for values your Atom reads through `$this->config()`:

```bash
printf '%s' "$PAYMENTS_API_KEY" | \
  vendor/bin/atoms secrets:set PAYMENTS_API_KEY --env production
```

This stores the Worker secret `ATOMS_CONFIG_PAYMENTS_API_KEY` — the name is
uppercased and prefixed — readable through `$this->config('PAYMENTS_API_KEY')`.
The prefix is what makes a Worker secret visible to Atom code at all, so
`ATOMS_SHARED_SECRET` and its rotation partner are not readable and cannot be
made readable: `secrets:set` refuses those names outright.

`secrets:list` shows which secrets an Atom can read and which it cannot.

A changed value is not retroactive: an Atom already resident in memory keeps the
value its isolate started with.

## Rotate the shared secret

Senders use `ATOMS_SHARED_SECRET`; verifiers accept that value and
`ATOMS_SHARED_SECRET_PREVIOUS`. Prepare the overlap before replacing the current
value. Starting with the same old secret on the application and Worker:

1. Configure every application instance with the old value as current and
   the new value as `ATOMS_SHARED_SECRET_PREVIOUS`. Reload the instances so
   all can verify callbacks signed with either value.
2. Set the Worker's `ATOMS_SHARED_SECRET_PREVIOUS` to the old value with
   `shared-secret:set --previous --force`. Let that change propagate before
   setting its current secret to the new value with `shared-secret:set --force`.
3. Configure the application with the new value as current and the old value
   as previous. Reload all application instances. Both sides now send with
   the new value and accept both.
4. After both deployments have updated and old tickets have expired, run
   `shared-secret:unset` on the Worker and remove the previous value from the
   application. Reload the application again.

Pass `--env production` to these commands. `shared-secret:set` reads the value
from stdin. Store both values in your secret manager during the rotation. Secret
changes propagate over time; verify application calls, callbacks, and browser
connections between stages.

Steps 1 and 3 are entirely yours — nothing in Atoms can reach your
application's secret store, so put them in your own deployment runbook. To drive
a rotation from CI, see
[Deploy from GitHub Actions](/guides/deploy/#deploy-from-github-actions).
