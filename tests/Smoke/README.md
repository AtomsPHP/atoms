# Local Laravel callback smoke

From the monorepo root, after `composer install` and `npm ci` in
`cloudflare/worker`:

```sh
node tests/Smoke/laravel-callback.mjs
```

Requires PHP on `PATH`, Node 22 or later, and Linux, macOS, or WSL2 for PHP
CLI server workers. CI runs it for each tested PHP/Laravel combination.

The test starts a temporary copy of the Laravel example and a local Worker.
It calls `/api/callback-check` and checks that the Atom retrieves the application
name through a signed callback. No Cloudflare account is needed.

The runner uses free loopback ports. Slow machines can adjust the test runner's
startup, request, and readiness polling budgets with
`ATOMS_SMOKE_STARTUP_MS`, `ATOMS_SMOKE_REQUEST_MS`, and `ATOMS_SMOKE_POLL_MS`.
These do not change runtime callback timeouts.
