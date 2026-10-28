---
title: Secrets and authentication
description: Set the shared secret that authenticates the app and Worker to each other, and the application secrets an Atom reads.
---

A Worker holds two kinds of secret:

- **`ATOMS_SHARED_SECRET`** authenticates your application and the Worker to
  each other. Both sides hold the same value.
- **Application secrets**, such as an API key, are values your Atom code reads
  with `$this->config()`.

## The shared secret

The shared secret is a base64 value that decodes to 32 random bytes. Generate
one, save it in your secret manager, and configure it as `ATOMS_SHARED_SECRET`
in your application and CI:

```bash
openssl rand -base64 32
```

After the first deploy, set the same value on the Worker:

```bash
printf '%s' "$ATOMS_SHARED_SECRET" | \
  vendor/bin/atoms shared-secret:set --env production
```

Until the Worker has a secret, every route except `/healthz` returns a
configuration error.

:::caution
`shared-secret:set` keeps an existing secret unless you pass `--force`. To
change the value, follow [Rotate the shared secret](#rotate-the-shared-secret).
:::

Both sides derive their keys from the shared secret: the bearer token your
application sends, the signature on callbacks from the Worker, and the
short-lived tickets browsers use to open WebSockets.
[`docs/shared-secret.md`](https://github.com/AtomsPHP/atoms/blob/main/docs/shared-secret.md)
has the derivations and test vectors.

### Bearer authentication

The Worker checks the bearer token your application sends with every call. If
an authenticating proxy such as Cloudflare Access sits in front of the Worker,
you can turn that check off by setting `ATOMS_BEARER_AUTH` to `disabled` in the
`vars` of `atoms-worker/wrangler.jsonc`. The shared secret is still required,
and browsers still open WebSockets with a
[ticket](/guides/websockets-timers/).

### Calling a protected route by hand

`atoms token` prints the bearer token for a shared secret. This example calls
the `join` method from the [overview](/):

```bash
curl -H "Authorization: Bearer $(vendor/bin/atoms token)" \
  -H 'Content-Type: application/json' \
  --data '{"args":["ada"]}' \
  https://your-worker.example.workers.dev/invoke/GameRoom/room-42/join
```

`atoms token` reads `ATOMS_SHARED_SECRET` from your shell, or from
`atoms-worker/.dev.vars` for a local Worker. It takes no `--env`: to get a
token for an environment, give it that environment's secret. You can read the
secret from your password manager for a single command:

```bash
BEARER=$(ATOMS_SHARED_SECRET=$(op read op://vault/atoms/shared-secret) \
  vendor/bin/atoms token)
```

## Application secrets

Use `secrets:set` for values your Atom reads through `$this->config()`:

```bash
printf '%s' "$PAYMENTS_API_KEY" | \
  vendor/bin/atoms secrets:set PAYMENTS_API_KEY --env production
```

This stores the Worker secret `ATOMS_CONFIG_PAYMENTS_API_KEY`, and your Atom
reads it with `$this->config('PAYMENTS_API_KEY')`. `secrets:list` shows which
of the Worker's secrets your Atoms can read.

An Atom that is already running keeps the old value until it next starts.

## Rotate the shared secret

During a rotation, each side sends with `ATOMS_SHARED_SECRET` and also accepts
`ATOMS_SHARED_SECRET_PREVIOUS`. Starting with the old secret as current on
both sides:

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

Pass `--env` to each Worker command; `shared-secret:set` reads the value from
stdin. The commands authenticate with Cloudflare [the same way as
`deploy`](/guides/deploy/#authenticate-with-cloudflare).
Secret changes take a little while to propagate, so check that application
calls, callbacks and browser connections work before moving to the next step.
Keep both values in your secret manager until the rotation is finished. To
rotate from CI, see [Deploy from GitHub
Actions](/guides/deploy/#deploy-from-github-actions).
