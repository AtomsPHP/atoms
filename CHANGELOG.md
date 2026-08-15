# Changelog

All notable changes to Atoms are documented here. The seven Composer packages,
the Cloudflare runtime, and deploy Action use one coordinated version.

## [0.1.1] - Unreleased

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
