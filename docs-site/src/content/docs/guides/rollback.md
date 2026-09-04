---
title: Rollback
description: Restore an earlier Worker version and understand what happens to existing data.
---

List Worker versions, then select the version you want to restore:

```bash
vendor/bin/atoms status --env production
vendor/bin/atoms rollback VERSION_ID --env production --message "restore known code"
```

Without a version id, Wrangler selects the previous **Worker version**.
Changing a secret also creates a Worker version, so the previous version
may contain the same code.

## What rollback changes

Rollback restores Worker code and compatible versioned configuration. It
does not reverse SQLite migrations or restore data. Cloudflare bindings,
such as the Durable Object namespace, are not rolled back with code.

The older code must work with the current schema. For example, removing a
column can prevent a rollback to code that still reads that column. Keep
schema changes compatible with every code version you may need to restore.

If a migration applied successfully but produced an unwanted schema, add a
corrective migration. If a migration failed to apply, resolve that failure
first: the migrator stops at the failed migration. See
[ATOMS-E053](/reference/errors/#atoms-e053).

Rollbacks take time to reach running Atoms. Verify application behavior
after rolling back.

## Data recovery

Atoms does not provide a point-in-time data recovery command. Cloudflare's
SQLite restore APIs must be called from inside the Durable Object. Exposing
them requires a custom Worker implementation that you maintain. See the
[runtime specification](https://github.com/AtomsPHP/atoms/blob/main/cloudflare/docs/runtime-spec.md)
for the runtime's storage and lifecycle behavior.
