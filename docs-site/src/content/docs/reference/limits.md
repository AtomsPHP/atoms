---
title: Limits
description: Cloudflare platform constraints and deliberately explicit Atoms runtime boundaries.
---

Atoms keeps platform limits visible. Defaults for request size, result size, SQL rows, bindings, deadlines, dispatches, WebSockets, and timers live in Worker configuration and can change across releases; inspect the runtime template you deploy rather than copying a number from a blog post.

## The clock does not advance inside a turn

On deployed workerd, the PHP guest clock remains frozen across CPU work and synchronous SQL. `sleep()`, `usleep()`, and loops waiting for elapsed time do not merely run slowly—they hang until the turn deadline terminates the residency.

Install the shipped enforcement:

```bash
composer require --dev atoms/phpstan-rules:^0.1
```

```text
includes:
    - vendor/atoms/phpstan-rules/rules.neon
```

The rules report [ATOMS-E101](/reference/errors/#atoms-e101) for sleep-family calls in Atom code and [ATOMS-E102](/reference/errors/#atoms-e102) for elapsed-time wait loops. They operate within one method and do not chase an arbitrary call graph; the runtime deadline remains the backstop.

Use a named [timer](/guides/websockets-timers/#timers) to act in a later turn, or `dispatch()` to hand work to the application.

## Wide integers require an explicit read shape

Cloudflare’s SQLite API exposes numeric results to JavaScript as numbers. An INTEGER above `2^53-1` may already be rounded before the Atoms bridge sees it, so the default runtime refuses the ambiguous result instead of returning a wrong value.

Store signed 64-bit integers normally, but select them as text when reading:

```sql
SELECT CAST(large_integer AS TEXT) AS large_integer FROM counters;
```

PHP integer bindings are encoded through the runtime’s validated integer path. The risk is the result crossing from Cloudflare SQLite into JavaScript.

## PDO is a compatibility shim

`$this->db()->pdo()` looks like PDO but executes against Durable Object SQLite across the PHP↔JavaScript bridge. Unsupported members and fetch shapes throw typed exceptions rather than returning invented answers. Review [PDO compatibility](/reference/pdo/) before porting code that relies on driver attributes, extension callbacks, duplicate column names, or uncommon fetch modes.

## Payloads and results are bounded

Invocation bodies, callback bodies, callback results, SQL row counts, SQL result bytes, nesting depth, and binding counts are bounded through Worker configuration. Exceeding a boundary returns a stable error. Do not use one Atom call as bulk export transport; page results and keep callback DTOs focused.

Binary values crossing JSON are tagged and base64 encoded. WebSocket binary frames remain binary on the socket side, but callback and invocation envelopes are JSON.

## Delivery semantics

- `dispatch()` is at-most-once, unordered, and unretried. A transport failure is logged and dropped.
- A timer is one-shot and at-most-once; handler failure is not retried automatically.
- WebSocket sends are not part of a SQL transaction and remain visible after rollback.
- `app()` cannot run inside a database transaction.
- Cloudflare deployment and secret propagation are eventual, with no per-Atom convergence signal.

## Memory and residency

PHP guest memory and Worker isolate memory share a finite platform envelope across colocated Durable Objects. Avoid retaining bulk results or treating per-residency PHP properties as durable storage. Cloudflare may evict idle compute at any time; SQLite, hibernatable sockets, and alarms are the durable mechanisms.

## Recovery boundary

The stock runtime has no point-in-time restore route or CLI command. Cloudflare’s restore primitives are callable only from inside the Durable Object. A custom Worker fork can expose an authenticated administrative surface, but it is unsupported and must be maintained by the operator. An unrelated Worker cannot directly open an Atom’s private SQLite database.

Atoms 0.1 supports the Workers Paid plan. See Cloudflare’s current [Durable Objects pricing](https://developers.cloudflare.com/durable-objects/platform/pricing/) for billing terms; Cloudflare’s own analytics documentation explains the account-level and per-object telemetry available to operators.
