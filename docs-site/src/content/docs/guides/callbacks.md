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

The default `InMemoryNonceStore` forgets a callback's nonce when the store
instance ends. A replay sent to a new instance can pass verification while its
signed timestamp is still valid. This affects PHP-FPM, multiple workers on one
host, and applications that rebuild the callback kernel or container for each
request. A Laravel singleton or Symfony shared service lasts only as long as
its container.

Use a persistent shared `Atoms\Client\Callback\NonceStore` for these deployments.
All callback workers must use the same store for the application and environment.
The store's `seen()` method must check and record a nonce in one atomic operation.
It returns `false` for the request that first records the nonce and `true` for
later requests. Separate read and write operations let concurrent replays pass.
If the store is unavailable, fail the request before running a Methods class or
queuing a job. Do not fall back to an empty in-memory store.

Keep each nonce for the entire interval in which its signed timestamp can pass
verification. With a timestamp window of `W` seconds, the kernel accepts a
timestamp up to `W` seconds ahead of its clock and up to `W` seconds behind it.
A store that measures retention from first receipt therefore needs at least
`2 * W + 1` seconds with aligned clocks. Use the largest window across your
workers and add time for clock differences between workers and the cleanup
process. The default window is 300 seconds. The configuration examples below
use 900 seconds of retention; increase it if your window or clock allowance
requires more. Do not evict or clear unexpired nonces during cache flushes,
deployments, or shared-secret rotation.

### Database example

This application-owned example uses PDO with PostgreSQL or SQLite. It runs in
your host application. Create the table through your application's migrations:

```sql title="callback_nonces.sql"
CREATE TABLE atoms_callback_nonces (
    nonce_hash VARCHAR(64) PRIMARY KEY,
    expires_at BIGINT NOT NULL
);
CREATE INDEX atoms_callback_nonces_expiry ON atoms_callback_nonces (expires_at);
```

Save the class as `app/Callbacks/DatabaseNonceStore.php` in Laravel or
`src/Callbacks/DatabaseNonceStore.php` in Symfony:

```php title="DatabaseNonceStore.php"
<?php

namespace App\Callbacks;

use Atoms\Client\Callback\NonceStore;
use PDO;

final class DatabaseNonceStore implements NonceStore
{
    public function __construct(
        private readonly PDO $database,
        private readonly int $retentionSeconds,
    ) {
        if ($retentionSeconds < 1) {
            throw new \InvalidArgumentException('Nonce retention must be positive.');
        }

        $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function seen(string $nonce): bool
    {
        if ($this->database->inTransaction()) {
            throw new \LogicException('Nonce records need a separate autocommit connection.');
        }

        $statement = $this->database->prepare(
            'INSERT INTO atoms_callback_nonces (nonce_hash, expires_at) VALUES (?, ?) '
            . 'ON CONFLICT (nonce_hash) DO NOTHING',
        );
        $statement->execute([
            hash('sha256', $nonce),
            time() + $this->retentionSeconds,
        ]);

        return $statement->rowCount() === 0;
    }
}
```

The primary key and `ON CONFLICT` make the insert atomic. An existing nonce
leaves the table unchanged and returns `true`. See the
[PostgreSQL](https://www.postgresql.org/docs/current/sql-insert.html#SQL-ON-CONFLICT)
and [SQLite](https://www.sqlite.org/lang_upsert.html) syntax references.
This SQL is not a MySQL example.

Use a dedicated connection in autocommit mode so application transactions
cannot roll back nonce records. For several application hosts, point every
connection at the same PostgreSQL database and table. On one host, SQLite can
use a persistent file writable by all PHP workers. `sqlite::memory:` or a
separate file per worker does not share state. Use a separate table or database
for each application and environment.

Run cleanup from a scheduled application command. Prepare
`DELETE FROM atoms_callback_nonces WHERE expires_at < ?` on the same database
and bind the cleanup process's current Unix time. Keep its clock synchronized
with the callback workers. Late cleanup retains extra rows and continues to
reject their nonces; early cleanup can admit a replay.

The [Laravel](/getting-started/laravel/#configure-callback-replay-protection) and
[Symfony](/getting-started/symfony/#configure-callback-replay-protection) guides
show how to bind this class. Plain-PHP hosts can pass it as `nonceStore:` to
`CallbackKernelFactory::create()`.

### Check the shared store

In a local test, construct two callback kernels with separate store objects and
connections to the same database. Send the same valid signed callback to both
within its timestamp window. The first response must be `200`, the second
`401` with `ATOMS-E065`, and the Methods call or job must run once. Rebuilding a
kernel must not make that callback valid again.

The repository's
[`CallbackNonceGuideTest`](https://github.com/AtomsPHP/atoms/blob/main/tests/Integration/CallbackNonceGuideTest.php)
loads the example above and checks this sequence using a temporary SQLite file.

## Synchronous `app()`

`$this->app()->method(...)` calls into the Atom's [Methods class](/guides/methods/) and waits for the response; the Atom is blocked for the whole round trip. Do not call it inside `$this->db()->transaction()`.

## Asynchronous `dispatch()`

`dispatch()` hands a job to your application's queue and returns immediately. See [Jobs](/guides/jobs/) for writing one and the delivery guarantees.

Callback request and response size limits are configurable via Workers environment variables.
