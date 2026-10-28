---
title: PDO compatibility
description: How $this->db()->pdo() differs from native pdo_sqlite.
---

`$this->db()->pdo()` runs queries against the Atom's SQLite database on
Cloudflare. It supports part of the native PDO API; unsupported operations
throw `PDOException`.

The [compatibility matrix](https://github.com/AtomsPHP/atoms/blob/main/cloudflare/docs/pdo-compatibility.md)
runs the same operations against Atoms and native `pdo_sqlite` and records the
result for each method and fetch mode. Its "How to read this" table explains
each status.

## Important differences

- SQLite extension callbacks such as `sqliteCreateFunction()`, aggregates, and
  collations are not available: PHP code cannot run inside Cloudflare's SQLite
  engine.
- `getAttribute(PDO::ATTR_SERVER_VERSION)` and
  `getAttribute(PDO::ATTR_CLIENT_VERSION)` throw, because PHP and the database
  use different SQLite builds.
- Give each result column a unique name. Duplicate names lose values before
  results reach PHP; this also affects positional and grouping fetch modes.
- A whole-number REAL value, such as `2.0`, can come back as a PHP integer.
- Select INTEGER values beyond JavaScript's safe range as text; see
  [Read large integers as text](/reference/limits/#read-large-integers-as-text).
- Binary values (`PDO::PARAM_LOB`, SQLite BLOBs) are not supported; see
  [Binary data](/reference/limits/#binary-data).
- `prepare()` accepts an empty options array or
  `[PDO::ATTR_CURSOR => PDO::CURSOR_FWDONLY]`. Other options throw.

For example, alias both `id` columns in a join:

```sql
SELECT players.id AS player_id, teams.id AS team_id
FROM players JOIN teams ON teams.id = players.team_id;
```
