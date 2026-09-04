---
title: Limits
description: Runtime limits, configuration defaults, and Cloudflare constraints.
---

## Configurable runtime limits

These defaults come from the runtime's `src/config.js`. Set overrides in the
`vars` section of `atoms-worker/wrangler.jsonc`. That file is shared by every
environment you deploy.

| Setting | Default | Applies to |
|---|---|---|
| `ATOMS_MAX_REQUEST_BYTES` | 1,048,576 bytes (1 MiB) | One invocation body |
| `ATOMS_MAX_ATOM_ID_BYTES` | 256 bytes | One Atom id |
| `ATOMS_MAX_JSON_DEPTH` | 64 levels | Invocation arguments |
| `ATOMS_SQL_MAX_ROWS` | 100,000 rows | One SQL result |
| `ATOMS_SQL_MAX_RESULT_BYTES` | 8,388,608 bytes (8 MiB) | One SQL result |
| `ATOMS_SQL_MAX_BINDINGS` | 1,000 values | One SQL statement |
| `ATOMS_TURN_DEADLINE_MS` | 30,000 ms | Total time awaiting callbacks in one turn |
| `ATOMS_CALLBACK_TIMEOUT_MS` | 10,000 ms | One callback request |
| `ATOMS_CALLBACK_MAX_REQUEST_BYTES` | 1,048,576 bytes (1 MiB) | One callback request body |
| `ATOMS_CALLBACK_MAX_RESPONSE_BYTES` | 1,048,576 bytes (1 MiB) | One synchronous callback response |
| `ATOMS_MAX_DISPATCHES_PER_TURN` | 100 jobs | One turn |
| `ATOMS_WS_MAX_CHANNELS` | 8 channels | One WebSocket connection |
| `ATOMS_WS_MAX_MESSAGE_BYTES` | 131,072 bytes (128 KiB) | One incoming WebSocket message |
| `ATOMS_WS_MAX_SEND_BYTES` | 131,072 bytes (128 KiB) | One outgoing message or broadcast frame |
| `ATOMS_WS_MAX_BROADCAST_SOCKETS` | 1,000 sockets | One broadcast |
| `ATOMS_TIMERS_MAX` | 10,000 timers | One Atom |
| `ATOMS_TIMER_NAME_MAX_BYTES` | 256 bytes | One timer name |
| `ATOMS_TIMERS_MAX_PER_ALARM` | 100 timers | One alarm event; remaining work is rescheduled |

These settings do not increase Cloudflare's platform limits. In particular,
the callback deadline does not limit PHP CPU execution. The platform enforces
its own CPU and memory limits. Check the
[Durable Objects limits](https://developers.cloudflare.com/durable-objects/platform/limits/)
for your deployment.

## The clock does not advance inside a turn

On the deployed runtime, the PHP clock does not advance during CPU work or synchronous SQL. `sleep()`, `usleep()`, and elapsed-time loops can block until Cloudflare resets the Atom for exceeding its CPU limit. The Atoms callback deadline cannot interrupt them.

Install the PHPStan rules:

```bash
composer require --dev atoms/phpstan-rules:^0.5
```

```text
includes:
    - vendor/atoms/phpstan-rules/rules.neon
```

The rules report [ATOMS-E101](/reference/errors/#atoms-e101) for sleep-family calls in Atom code and [ATOMS-E102](/reference/errors/#atoms-e102) for elapsed-time wait loops. They check each method individually and may miss waits reached through other method calls.

Use a named [timer](/guides/websockets-timers/#timers) to act in a later turn, or `dispatch()` to hand work to the application.

## Read large integers as text

Cloudflare’s SQLite API exposes numeric results to JavaScript as numbers. An INTEGER above `2^53-1` may already be rounded before the Atoms bridge sees it, so the default runtime refuses the ambiguous result instead of returning a wrong value.

Store signed 64-bit integers normally, but select them as text when reading:

```sql
SELECT CAST(large_integer AS TEXT) AS large_integer FROM counters;
```

The runtime preserves signed 64-bit integer values when writing them. The precision limit applies when reading numeric results through JavaScript; it also affects negative integers below `-(2^53-1)`.

## PDO is a compatibility shim

`$this->db()->pdo()` looks like PDO but executes against Durable Object SQLite across the PHP↔JavaScript bridge. Unsupported operations and fetch modes throw `PDOException`. Review [PDO compatibility](/reference/pdo/) before porting code that relies on driver attributes, extension callbacks, duplicate column names, or uncommon fetch modes.

## Payloads and results are bounded

Invocation bodies, callback bodies, callback results, SQL row counts, SQL result bytes, nesting depth, and binding counts are bounded through Worker configuration. Exceeding a boundary returns a stable error. Page large query results and send only the data a callback needs.

Binary values crossing JSON are tagged and base64 encoded. WebSocket binary frames remain binary on the socket side, but callback and invocation envelopes are JSON.

## Delivery semantics

- `dispatch()` is at-most-once, unordered, and unretried. A transport failure is logged and dropped.
- A timer is one-shot and at-most-once; handler failure is not retried automatically.
- WebSocket sends are not part of a SQL transaction and remain visible after rollback.
- `app()` cannot run inside a database transaction.
- Deployments and secret changes take time to reach running Atoms. Atoms cannot report when every Atom has received a change.

## Memory and persistence

PHP and the Worker share the available runtime memory. Process large results in pages and store persistent state in SQLite. PHP properties are lost when Cloudflare evicts an idle Atom; its database, hibernating WebSocket connections, and scheduled timers survive.

## Data recovery and billing

See [Rollback](/guides/rollback/#data-recovery) for data recovery limitations.
Atoms 0.5 requires the Workers Paid plan. See
[Durable Objects pricing](https://developers.cloudflare.com/durable-objects/platform/pricing/)
for billing details.
