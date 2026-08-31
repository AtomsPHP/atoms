---
title: Rollback
description: Move a Cloudflare Worker to an earlier version without misrepresenting data recovery.
---

List Worker versions, then select one explicitly when possible:

```bash
vendor/bin/atoms status --env production
vendor/bin/atoms rollback VERSION_ID --env production --message "restore known code"
```

Without a version id, Wrangler selects the previous **Worker version**. That is not necessarily the previous code bundle: changing a secret also creates a Worker version.

## What rollback changes

Rollback moves Worker code and compatible versioned configuration. It does not reverse SQLite migrations, delete rows, or restore data. Cloudflare bindings such as the Durable Object namespace are not rolled back with code.

Keep migrations forward-compatible across the rollback window. Because deployed migrations are immutable, recovery from a faulty schema change normally means a new corrective migration plus a compatible code version.

Like deploys and secret changes, rollbacks propagate eventually. Verify behavior before treating the rollback as globally in force.

## Point-in-time recovery is not exposed

Cloudflare maintains SQLite Durable Object history and exposes restore primitives from inside the Durable Object. The stock Atoms 0.1 runtime does not provide a restore route or CLI command, and Wrangler or Data Studio cannot restore an Atom on its behalf.

An advanced operator can maintain a custom Worker fork that exposes those primitives to an authenticated administrative surface. That fork is outside the supported runtime and must preserve the Atom’s namespace and storage identity. Do not imply that an unrelated companion Worker can directly access another Durable Object’s private SQLite storage.
