# Local Laravel callback smoke

From the monorepo root, after `composer install` and `npm ci` in
`cloudflare/worker`:

```sh
node tests/Smoke/laravel-callback.mjs
```

Requires PHP on `PATH`, Node 22 or later, and PHP CLI server worker support
(Linux, macOS, or WSL2). CI runs it for each tested PHP/Laravel combination.
It fails on native Windows instead of silently skipping the callback check.

The runner copies the maintained Laravel example into a temporary directory,
reuses the installed monorepo dependencies, scaffolds the runtime from local
source, and starts `atoms dev` plus the documented concurrent Artisan server.
It requests `/api/callback-check` over HTTP and verifies the real Atom returns
a per-run application name through the signed callback endpoint. There is no
fake HTTP transport or harness callback; no Cloudflare account or deployed
service is involved. Runtime callback and turn timeouts retain their defaults.
Temporary files and both process groups are removed on completion or failure.

The runner uses free loopback ports. Slow machines can adjust the test runner's
startup, request, and readiness polling budgets with
`ATOMS_SMOKE_STARTUP_MS`, `ATOMS_SMOKE_REQUEST_MS`, and `ATOMS_SMOKE_POLL_MS`.
These do not change runtime callback timeouts.
