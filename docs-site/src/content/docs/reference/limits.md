---
title: Limits
description: Runtime limits and their defaults, Cloudflare platform limits, and the constraints Atom code runs under.
---

## Cloudflare plan and platform limits

For platform limits and billing, see
[Workers limits](https://developers.cloudflare.com/workers/platform/limits/),
[Durable Objects limits](https://developers.cloudflare.com/durable-objects/platform/limits/),
and [Durable Objects pricing](https://developers.cloudflare.com/durable-objects/platform/pricing/).

## Configurable runtime limits

Override any of these in the `vars` section of `atoms-worker/wrangler.jsonc`.

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

An invocation, callback, or SQL result over its limit fails with an error
instead of being truncated. Page large query results, and send a callback only
the data it needs.

## The clock does not advance inside a turn

On the deployed runtime, the PHP clock does not advance during CPU work or
synchronous SQL. `sleep()`, `usleep()`, and elapsed-time loops can block until
Cloudflare resets the Atom for exceeding its CPU limit.

Install the PHPStan rules:

```bash
composer require --dev atoms/phpstan-rules:^0.8
```

```text
includes:
    - vendor/atoms/phpstan-rules/rules.neon
```

The rules report [ATOMS-E101](/reference/errors/#atoms-e101) for sleep-family
calls in Atom code and [ATOMS-E102](/reference/errors/#atoms-e102) for
elapsed-time wait loops. They check each method on its own, so they can miss
a wait reached through another method call.

Use a named [timer](/guides/websockets-timers/#timers) to act in a later turn,
or `dispatch()` to hand work to your application.

## Read large integers as text

Cloudflare's SQLite API returns numbers to the runtime as JavaScript numbers,
so an INTEGER outside `-(2^53-1)` through `2^53-1` can be rounded before
Atoms sees it. By default, the runtime rejects integral numbers with magnitude
above `2^53-1` and at most `2^63` with `int64_precision`. This also affects
whole-number REAL values in that range, which the runtime cannot distinguish
from rounded INTEGERs.

Writing signed 64-bit integers is exact. When reading them, select them as
text:

```sql
SELECT CAST(large_integer AS TEXT) AS large_integer FROM counters;
```

## Binary data

Arguments, return values, SQL values, and callback payloads travel as JSON, so
they must be valid UTF-8. Base64-encode binary data yourself and store it as
text; SQLite BLOB values and `PDO::PARAM_LOB` are not supported. WebSocket
messages are the exception: `send()` delivers bytes that are not valid UTF-8 as
a binary frame.

## See also

- [PDO compatibility](/reference/pdo/) for how `$this->db()->pdo()` differs
  from native PDO.
- [Lifecycle and persistence](/concepts/lifecycle/) for what survives when an
  idle Atom is evicted.
- [Jobs](/guides/jobs/#delivery-guarantees) and
  [WebSockets and timers](/guides/websockets-timers/) for delivery guarantees.
- [Rollback](/guides/rollback/#data-recovery) for data recovery.
