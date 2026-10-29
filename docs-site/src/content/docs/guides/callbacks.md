---
title: Callbacks
description: Configure signed app() calls and dispatch() jobs from an Atom back to your application.
---

An Atom can cross from Atom-side back into the host application in two ways:

- `$this->app()->method(...)` is synchronous reverse RPC into a method defined in an Atom's `Methods` class.
- `$this->dispatch(Job::class, [...])` hands an [`AtomJob`](/guides/jobs/) to the host's queue bridge.

## Configure the channel

Callbacks need a callback URL and the shared secret.

Set the callback URL on each environment in `atoms.json`. This is where the
Worker sends callbacks to your application:

```json
{
    "environments": {
        "production": {
            "worker_name": "my-app",
            "callback_url": "https://example.com/atoms/callback"
        }
    }
}
```

The URL must use HTTPS, except for local development on `localhost`. To use a
different URL on your own machine, such as a tunnel, set `ATOMS_CALLBACK_URL`
in `.env.atoms.<environment>`. [Environments](/guides/environments/#where-values-come-from)
lists every place the URL can come from. Your application's `ATOMS_ENDPOINT`
is a separate setting that points the other way, from your application to the
Worker.

Configure the shared secret on both your application and the Worker, as
[Secrets and authentication](/guides/secrets/) describes. Every callback is
signed with a key derived from it, and your adapter verifies the signature
before your Methods class or job runs. See the [adapter
contract](/concepts/adapters/) for what a host must provide.

## Store callback nonces

Laravel and Symfony ship Redis and database nonce stores. Select one through
`callback_nonce` configuration. The [Laravel setup](/getting-started/laravel/#configure-callback-replay-protection)
and [Symfony setup](/getting-started/symfony/#configure-callback-replay-protection)
show the settings and migration commands.

### Upgrading an existing callback application

Callbacks now require a configured nonce store. The adapters and
`CallbackKernelFactory` no longer select in-memory storage by default. Before
upgrading an application that uses `app()` or `dispatch()`, configure Redis or
database storage. For the database backend, run your framework's migrations
before accepting callbacks. No nonce table is created during a request.

A valid signed callback returns `503` with [ATOMS-E111](/reference/errors/#atoms-e111)
when storage is unconfigured or its settings are invalid. Storage failures,
including a missing table, return `503` with [ATOMS-E112](/reference/errors/#atoms-e112).
Neither response invokes a Methods class or queues a job. Application boot and
outbound-only Atom calls do not require callback storage.

`InMemoryNonceStore` remains available through the explicit `memory` driver for
development and tests. It loses its history when a request reconstructs the
store, does not share records with other workers, and can evict nonces when
full. Even a single PHP-FPM worker rebuilds application state between requests.
Use a shared store when testing callbacks through a web server too.

### Shared storage requirements

All callback workers for an application and environment must use the same
backend and namespace. Keep the namespace stable across deployments and
shared-secret rotation. For example, use `orders:production` for every
production callback worker, and a separate namespace for staging.

Each store records a nonce atomically. Redis uses one command with expiry;
the database stores use a unique constraint. The first request records the
nonce and proceeds. Later requests with that nonce return `401` with
[ATOMS-E065](/reference/errors/#atoms-e065).

Database storage supports MySQL/MariaDB, PostgreSQL, and SQLite through
Laravel's database layer or Doctrine DBAL. On one host, SQLite requires a
persistent file that every PHP worker can access. An in-memory SQLite database
does not share state across connections. For multiple hosts, use the same
MySQL/MariaDB or PostgreSQL database, or Redis.

The adapters open a separate, nonpersistent database connection to the same
database used by their migration. An application transaction cannot roll back
a nonce record. Callback storage failures reject the request; there is no
fallback to an empty store.

### Retention

The kernel accepts a callback timestamp up to `W` seconds ahead of its clock
and up to `W` seconds behind it. A nonce first received at the start of that
interval must remain recorded until the interval ends.

The default retention is `2 * W + 1 + clock_allowance_seconds`. With the default
300-second timestamp window and 60-second clock allowance, that is 661 seconds.
Leave `retention_seconds` unset to calculate it from these settings. An explicit
retention below the calculated minimum is rejected.

Use the largest timestamp window across your callback workers when setting
retention. Increase the clock allowance if differences between workers and the
pruning process can exceed it. Keep their clocks synchronized. If you increase
the timestamp window during a rollout, increase retention on every worker first
and wait out the new retention interval before widening the window.

### Cleanup and Redis isolation

Redis expires nonce records automatically. Use a dedicated Redis connection
and database or instance that ordinary application cache clears do not flush.
Configure Redis to retain unexpired nonce keys. A key prefix prevents collisions
but does not protect against `FLUSHDB`, `FLUSHALL`, eviction, or data loss.

For database storage, schedule the shipped pruning command:

```bash
# Laravel
php artisan atoms:nonces:prune

# Symfony
php bin/console atoms:nonces:prune
```

Pruning removes only expired records. Late pruning uses extra database space;
it does not admit replays. Both commands use the configured nonce database.
Do not clear unexpired records during deployments, cache maintenance, or
secret rotation.

Switching to Redis leaves the database table and its migration history in
place. To roll back that migration intentionally, select the database driver
again so your framework discovers it, then use the framework's rollback
procedure. Stop accepting callbacks before removing their storage.

### Custom stores and plain PHP

Both framework adapters preserve an explicit binding for
`Atoms\Client\Callback\NonceStore`. Its `seen()` method must check and record a
nonce atomically, return `false` for the first claim, and return `true` for a
replay. Throw on storage failure. Keep records for the full retention interval.

Plain-PHP applications can pass a shared store as `nonceStore:` to
`CallbackKernelFactory::create()`. The shipped `RedisNonceStore::fromClient()`
accepts a PhpRedis or Predis client and `NonceStoreOptions::fromArray()` accepts
the namespace and retention settings described above. Omitting the store now
rejects callbacks. Pass an `InMemoryNonceStore` explicitly only for tests that
keep the same instance alive.

## Synchronous `app()`

`$this->app()->method(...)` calls into the Atom's [Methods class](/guides/methods/) and waits for the response; the Atom is blocked for the whole round trip. Do not call it inside `$this->db()->transaction()`.

## Asynchronous `dispatch()`

`dispatch()` hands a job to your application's queue and returns immediately. See [Jobs](/guides/jobs/) for writing one and the delivery guarantees.

Callback request and response size limits are configurable via Workers environment variables.
