# Local smoke tests

From the monorepo root, after `composer install` and `npm ci` in
`cloudflare/worker`:

```sh
node --test --test-concurrency=1 tests/Smoke/*.test.mjs
```

Requires PHP on `PATH`, Node 22 or later, and Linux, macOS, or WSL2 for PHP
CLI server workers. CI runs the suite for each tested PHP/Laravel combination.

Add smoke tests as `*.test.mjs` files in this directory. The command above
discovers them automatically; no workflow entry or test registry is needed.
Node runs each file in a separate process, one at a time, and fails the suite
when a script exits unsuccessfully or an assertion fails. Keep helpers and
fixtures out of the `*.test.mjs` pattern.

`laravel-callback.test.mjs` starts a temporary copy of the Laravel example and a
local Worker.
It calls `/api/callback-check` and checks that the Atom retrieves the application
name through a signed callback. No Cloudflare account is needed.

`broadcast-dashboard.test.mjs` builds the guide's broadcast-only Atom, connects
with a signed WebSocket ticket, and checks that publishing delivers an update.

To run one smoke test, pass its path to `node --test`.

The runner uses free loopback ports. Slow machines can adjust the test runner's
startup, request, and readiness polling budgets with
`ATOMS_SMOKE_STARTUP_MS`, `ATOMS_SMOKE_REQUEST_MS`, and `ATOMS_SMOKE_POLL_MS`.
These do not change runtime callback timeouts.
