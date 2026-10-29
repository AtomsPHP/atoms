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
- Give each result column a unique name; see
  [Duplicate column names](#duplicate-column-names).
- A whole-number REAL value, such as `2.0`, can come back as a PHP integer.
- Select INTEGER values beyond JavaScript's safe range as text; see
  [Read large integers as text](/reference/limits/#read-large-integers-as-text).
- Binary values (`PDO::PARAM_LOB`, SQLite BLOBs) are not supported; see
  [Binary data](/reference/limits/#binary-data).
- `prepare()` accepts an empty options array or
  `[PDO::ATTR_CURSOR => PDO::CURSOR_FWDONLY]`. Other options throw.

## Duplicate column names

Cloudflare returns rows keyed by column name, so duplicate names keep only the
last value. `PDO::FETCH_ASSOC` and `PDO::FETCH_OBJ` return that value, as native
PDO does. Use unique aliases to keep every column.

These modes need values or positions that Cloudflare has discarded. Fetching
a row with duplicate names throws `PDOException` with `SQLSTATE[HY000]`:

| Operation | Fetch modes or arguments |
| --- | --- |
| `fetch()` or `fetchAll()` | `PDO::FETCH_NUM`, `PDO::FETCH_BOTH`, or `PDO::FETCH_COLUMN` |
| `fetchColumn()` | The default or an explicit column index |
| `fetch()` or `fetchAll()` | `PDO::FETCH_NAMED` or `PDO::FETCH_BOUND`, including columns bound by name or index |
| `fetch()` or `fetchAll()` | `PDO::FETCH_CLASS \| PDO::FETCH_CLASSTYPE`, optionally with `PDO::FETCH_PROPS_LATE` |
| `fetchAll()` | `PDO::FETCH_FUNC` with a callback, or `PDO::FETCH_KEY_PAIR` with exactly two same-name columns |
| `fetchAll()` | `PDO::FETCH_GROUP` or `PDO::FETCH_UNIQUE` combined with a supported `PDO::FETCH_ASSOC`, `PDO::FETCH_NUM`, `PDO::FETCH_BOTH`, or `PDO::FETCH_COLUMN` mode |

Any duplicate in the row triggers this check, regardless of how the fetch mode
was set or which column was requested. It runs when fetching or iterating.
The default mode is `PDO::FETCH_BOTH`, so a plain `fetch()` can throw too.

For example, alias both `id` columns in a join:

```sql
SELECT players.id AS player_id, teams.id AS team_id
FROM players JOIN teams ON teams.id = players.team_id;
```
