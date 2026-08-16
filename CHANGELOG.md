# Changelog

All notable changes to Atoms are documented here. The seven Composer packages,
the Cloudflare runtime, and deploy Action use one coordinated version.

## [0.2.0] - Unreleased

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
- **Fixed:** A ticket signature's base64url decoding discarded a trailing
  segment's unused "wasted" bits, so a tampered signature could sometimes
  decode to the identical bytes as the real one and still verify — a
  malleability gap in the ticket check above. `verifyTicket()`'s base64url
  decoder now rejects any non-canonical encoding (round-trip re-encoding must
  reproduce the exact input) for both the signature and payload segments.
- **Added:** Rotation overlap via `ATOMS_SHARED_SECRET_PREVIOUS`, accepted at
  exactly two verification sites (the Worker's bearer check, the monolith's
  callback verification), try-both, never a key selector; a sender always
  emits under the current secret only. Tickets get no overlap — rotating the
  secret invalidates every outstanding ticket at once. See
  `docs/shared-secret.md` §Rotation for the runbook.
- **Changed:** `atoms dev` provisions a per-machine dev secret instead of
  running keyless, so local and production run the identical auth code path
  including signed tickets. The app's `.env` (or `.env.local` where `.env` is
  committed, as in Symfony) is the source of truth: a secret is generated
  there when the key is absent, an existing one is adopted untouched, and the
  Worker's gitignored `.dev.vars` is a generated one-line projection rewritten
  whenever the two differ. No manual copy, and the value is never printed.
  `.dev.vars` exists only because `wrangler dev` reads that file and nothing
  else — treat it as a build artifact. A project with no dotenv file keeps the
  secret in `.dev.vars` alone.

### UPGRADING to 0.2.0

This release deletes four variables outright — `ATOMS_APP_KEY`,
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

- **Changed:** the scaffolded Worker project (`@atomsphp/runtime-cloudflare`)
  now ships with the Worker's `/debug` routes **off**, matching the Worker's
  own default. The template's `wrangler.jsonc` is built from a new
  `wrangler.scaffold.jsonc` (worker name `atoms-worker`) instead of the
  conformance harness's config, which had leaked its `ATOMS_DEBUG_ENDPOINTS=1`
  var and `atoms-mvp-conformance` name into customer deployments. The
  conformance suite still runs with the flag on via the harness's own
  `wrangler.jsonc`, which no longer ships.
- **Added:** a supported, re-scaffold-proof way to enable debug endpoints:
  `"debug_endpoints": true` on an environment in `atoms.json`. Both
  `atoms dev` and `atoms deploy` forward it to Wrangler as
  `--var ATOMS_DEBUG_ENDPOINTS:1`, and both print a line when it is in
  force. Editing the Worker directory's `wrangler.jsonc` was never durable —
  `.atoms/worker` is gitignored and the deploy Action regenerates it on a
  fresh checkout. The value must be a JSON boolean; a string such as
  `"false"` is refused (ATOMS-E070) rather than coerced. `/debug` stays
  behind the Worker's auth check; the flag is a second gate, except under
  `ATOMS_BEARER_AUTH=disabled` (an authenticating proxy in front of the
  Worker), where it is the only one — see `docs/cloudflare-toolchain.md`
  §Debug endpoints.

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

[0.2.0]: https://github.com/AtomsPHP/atoms/releases/tag/v0.2.0
