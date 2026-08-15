# Changelog

All notable changes to Atoms are documented here. The seven Composer packages,
the Cloudflare runtime, and deploy Action use one coordinated version.

## [Unreleased]

- **Security:** One operator-facing secret, `ATOMS_SHARED_SECRET` (32 random
  bytes, base64, identical on the app and the Worker, never transmitted),
  replaces `ATOMS_APP_KEY`, `ATOMS_API_KEY`, `ATOMS_CALLBACK_SIGNING_KEY` and
  `ATOMS_PLATFORM_PUBLIC_KEY`. Every key on the app ↔ Worker boundary — the
  bearer, WebSocket ticket signing, callback signing — is HKDF-SHA256 derived
  from it with per-purpose domain separation (`atoms/bearer/v1`,
  `atoms/ws-ticket/v1`, `atoms/callback/v1`). The four old variables are
  deleted, not aliased: a deployment still setting only the old names fails
  loudly. `ATOMS-E105` covers a missing or malformed secret. See
  `docs/shared-secret.md`, the decision record.
- **Added:** `atoms token` prints the bearer derived from
  `ATOMS_SHARED_SECRET`, so an operator can curl the Worker without ever
  putting the secret itself in an `Authorization` header.
- **Added:** `ATOMS_BEARER_AUTH` — `required` (the default) or `disabled` for
  an authenticating proxy such as Cloudflare Access in front of the Worker —
  is the explicit bearer-auth posture. An absent or malformed
  `ATOMS_SHARED_SECRET` makes every route except `GET /healthz` answer
  `misconfigured` (HTTP 500, `retryable: false`): a misconfigured Worker is
  loudly broken, never silently open.
- **Changed:** Callback signing uses HMAC-SHA256 over the existing envelope
  (`"v1\n{ts}\n{nonce}\n" + body`, the same headers), the key HKDF-derived
  from `ATOMS_SHARED_SECRET`, verified with `hash_equals()` against a 32-byte
  tag. `atoms/client` declares no `ext-sodium` dependency.
- **Removed:** The unsigned `v1u.` WebSocket ticket form. `POST /tickets`
  always mints a signed `v1.` ticket, including under
  `ATOMS_BEARER_AUTH=disabled`, since a shared secret — and therefore a
  signing key — is always configured.
- **Added:** Rotation overlap via `ATOMS_SHARED_SECRET_PREVIOUS`, accepted at
  exactly two verification sites (the Worker's bearer check, the monolith's
  callback verification), try-both, never a key selector; a sender always
  emits under the current secret only. Tickets get no overlap — rotating the
  secret invalidates every outstanding ticket at once. See
  `docs/shared-secret.md` §Rotation for the runbook.
- **Changed:** `atoms dev` provisions a fresh per-machine dev secret into the
  Worker project's gitignored `.dev.vars` (creating the file if needed, never
  overwriting a value already there) instead of running keyless. Local and
  production run the identical auth code path, including signed tickets.

### UPGRADING from 0.1.x

This change deletes four variables outright — `ATOMS_APP_KEY`,
`ATOMS_API_KEY`, `ATOMS_CALLBACK_SIGNING_KEY`, `ATOMS_PLATFORM_PUBLIC_KEY` —
with no compatibility shim. A deployment still setting only the old names
fails loudly: the Worker answers `misconfigured` on every route but
`GET /healthz`.

1. Generate one secret: `openssl rand -base64 32`.
2. Set it as `ATOMS_SHARED_SECRET`, identically, on both sides: on the
   Worker, `wrangler secret put ATOMS_SHARED_SECRET`; on the monolith,
   wherever its other secrets already live. It must never be sent over the
   wire — it is the root the bearer, ticket, and callback keys all derive
   from, not a bearer token itself.
3. Delete the four old variables — `ATOMS_APP_KEY`, `ATOMS_API_KEY`,
   `ATOMS_CALLBACK_SIGNING_KEY`, `ATOMS_PLATFORM_PUBLIC_KEY` — from both
   sides.
4. Set `ATOMS_BEARER_AUTH` if the deployment is not using the `required`
   default: `disabled` only when an authenticating proxy such as Cloudflare
   Access already sits in front of the Worker.
5. Every WebSocket ticket outstanding at cutover is invalidated — rotating
   the signing root always invalidates tickets, with no overlap. A
   reconnecting browser mints a fresh one automatically.
6. Update curl/troubleshooting examples: `-H "Authorization: Bearer
   $ATOMS_APP_KEY"` becomes `-H "Authorization: Bearer $(atoms token)"`.

Rolling this out with zero downtime for bearer auth and callbacks: set
`ATOMS_SHARED_SECRET_PREVIOUS` to the old secret alongside the new
`ATOMS_SHARED_SECRET` on both sides during the overlap window, then remove
`ATOMS_SHARED_SECRET_PREVIOUS` from both once every instance holds the new
secret. See `docs/shared-secret.md` §Rotation for the full runbook.

## [0.1.1] - 2026-08-15

- **Fixed:** `dispatch()` took an `AtomJob` instance, which could never work —
  a job's source lives in World B and is never packed into a deployed bundle,
  so every dispatch died at the call site with `Class "..." not found` (or
  vanished silently inside a `catch (\Throwable)`). `dispatch()` now takes the
  job's class name (`$this->dispatch(RecordGameResult::class, [...])`),
  resolved by the compiler from the caller's own `use` statement — no job code
  reaches the platform. This is a signature change, not an addition: the old
  `dispatch(AtomJob $job)` shape could never have worked from a deployed Atom,
  so there is no working caller to keep compatible.
- **Added:** WebSocket connection tickets, so a browser-facing deployment can
  run with `ATOMS_APP_KEY` set at all. `POST /tickets/:type/:id` mints a
  short-TTL, HMAC-SHA256-signed, atom-scoped ticket (reusable within its TTL,
  not single-use) presented as `?ticket=` on the `/ws` upgrade, since a
  browser `WebSocket` cannot send an `Authorization` header. Previously,
  enabling auth meant running with it off entirely, leaving `POST /invoke`
  open to the world. New wire codes `ticket_invalid` / `ticket_expired` and
  client-side catalog code `ATOMS-E067`. See `cloudflare/docs/mvp-spec.md`
  §Routing and auth.

## [0.1.0] - 2026-08-14

Initial open-source release of the Atoms programming model, Laravel and
Symfony adapters, testing and PHPStan tooling, deterministic CLI build and
deploy workflow, and the Cloudflare Durable Object PHP runtime.

[0.1.0]: https://github.com/AtomsPHP/atoms/releases/tag/v0.1.0

[0.1.1]: https://github.com/AtomsPHP/atoms/releases/tag/v0.1.1
