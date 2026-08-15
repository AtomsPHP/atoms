# Changelog

All notable changes to Atoms are documented here. The seven Composer packages,
the Cloudflare runtime, and deploy Action use one coordinated version.

## [0.1.2] - Unreleased

- **Changed:** the scaffolded Worker project
  (`@atomsphp/runtime-cloudflare`) now ships with the Worker's `/debug`
  routes **off**, matching the Worker's own default. The template's
  `wrangler.jsonc` is built from a new `wrangler.scaffold.jsonc` (worker
  name `atoms-worker`) instead of the conformance harness's config, which
  had leaked its `ATOMS_DEBUG_ENDPOINTS=1` var and `atoms-mvp-conformance`
  name into customer deployments. The conformance suite still runs with the
  flag on via the harness's own `wrangler.jsonc`, which no longer ships.
- **Added:** a supported, re-scaffold-proof way to enable debug endpoints:
  `"debug_endpoints": true` on an environment in `atoms.json`. Both
  `atoms dev` and `atoms deploy` forward it to Wrangler as
  `--var ATOMS_DEBUG_ENDPOINTS:1`, and both print a line when it is in
  force. Editing the Worker directory's `wrangler.jsonc` was never durable —
  `.atoms/worker` is gitignored and the deploy Action regenerates it on a
  fresh checkout. The value must be a JSON boolean; a string such as
  `"false"` is refused (ATOMS-E070) rather than coerced. `/debug` remains
  behind the Worker's auth check when that is enabled; the flag is a second
  gate, and with auth off (local dev, or access control terminated in front
  of the Worker) it is the only one — see `docs/cloudflare-toolchain.md`
  §Debug endpoints.

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

[0.1.2]: https://github.com/AtomsPHP/atoms/releases/tag/v0.1.2
