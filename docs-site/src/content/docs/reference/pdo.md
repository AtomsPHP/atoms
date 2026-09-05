---
title: PDO compatibility
description: How the Atoms PDO shim differs from native pdo_sqlite.
---

`$this->db()->pdo()` runs queries against the Atom's SQLite database through the Cloudflare runtime. It supports part of the native PDO API; unsupported operations throw `PDOException`.

The [compatibility matrix](https://github.com/AtomsPHP/atoms/blob/main/cloudflare/docs/pdo-compatibility.md) records tests that run the same operations against Atoms and native `pdo_sqlite`. Use it to check individual methods and fetch modes.

## Status vocabulary

| Status | Meaning |
|---|---|
| Supported | Result matches native `pdo_sqlite`. |
| Refused | Atoms throws a typed `PDOException` where native PDO answers. |
| Refused by both | Both drivers reject the operation, with differences recorded. |
| Differs | Both answer but a measured bridge or language-boundary difference remains. |
| Undefined | PDO itself does not define a portable result. |

## Important differences

- SQLite extension callbacks such as `sqliteCreateFunction()`, aggregates, and collations are permanently unavailable: PHP callbacks cannot run inside the Durable Object SQL engine.
- `getAttribute(PDO::ATTR_SERVER_VERSION)` and `getAttribute(PDO::ATTR_CLIENT_VERSION)` throw: the PHP runtime and Durable Object use different SQLite builds.
- Give each result column a unique name. Duplicate names lose information when query results cross from SQLite into PHP; this also affects positional and grouping fetch modes.
- Whole-number REAL values can cross back as PHP integers because workerd exposes INTEGER and REAL through the same JavaScript number type.
- Wide INTEGER results must be selected with `CAST(column AS TEXT)` to preserve values above JavaScript’s safe integer range.
- `prepare()` accepts an empty options array or `[PDO::ATTR_CURSOR => PDO::CURSOR_FWDONLY]`. Other options throw.

For example, alias both `id` columns in a join:

```sql
SELECT players.id AS player_id, teams.id AS team_id
FROM players JOIN teams ON teams.id = players.team_id;
```
