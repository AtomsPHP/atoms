---
title: Rollback
description: Restore an earlier Worker version and understand what happens to existing data.
---

List the Worker's versions, then restore the one you want:

```bash
vendor/bin/atoms status --env production
vendor/bin/atoms rollback VERSION_ID --env production --message "restore known code"
```

Without a version ID, `rollback` restores the previous Worker version. Setting
a secret also creates a version, so the previous version may have the same
code. Both commands authenticate with Cloudflare [the same way as
`deploy`](/guides/deploy/#authenticate-with-cloudflare).

## What rollback changes

Rollback restores the Worker's code and its versioned configuration. Your
Atoms' data and schema stay as they are, and so do Cloudflare bindings such as
the Durable Object namespace.

The older code must work with the current schema. For example, removing a
column can prevent a rollback to code that still reads that column. Keep
schema changes compatible with every code version you may need to restore.

If a migration applied successfully but produced an unwanted schema, add a
corrective migration. If a migration failed to apply, fix it first: the
migrator stops at the failed migration.

A rollback takes a little while to reach running Atoms. Check your
application's behavior afterwards.

## Data recovery

Atoms has no point-in-time recovery command. Cloudflare's SQLite restore APIs
run inside the Durable Object, so using them means maintaining your own custom
Worker.
