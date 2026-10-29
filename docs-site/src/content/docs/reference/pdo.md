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

Cloudflare's host returns each row as a map keyed by column name. When names
are duplicated, the last value wins before the row reaches PHP; the original
positions and overwritten values cannot be recovered. `PDO::FETCH_ASSOC`
returns the collapsed array and `PDO::FETCH_OBJ` returns an object with the
collapsed properties. Neither mode throws for duplicate names, matching
native PDO's name-based collapse.

When reading a row with duplicate names, the following operations throw a
`PDOException` whose message begins `SQLSTATE[HY000]`:

| Operation | Fetch modes or arguments |
| --- | --- |
| `fetch()` or `fetchAll()` | `PDO::FETCH_NUM`, `PDO::FETCH_BOTH`, or `PDO::FETCH_COLUMN` |
| `fetchColumn()` | The default or an explicit column index |
| `fetch()` or `fetchAll()` | `PDO::FETCH_NAMED` or `PDO::FETCH_BOUND`, including columns bound by name or index |
| `fetch()` or `fetchAll()` | `PDO::FETCH_CLASS \| PDO::FETCH_CLASSTYPE`, optionally with `PDO::FETCH_PROPS_LATE` |
| `fetchAll()` | `PDO::FETCH_FUNC` with a callback, or `PDO::FETCH_KEY_PAIR` with exactly two same-name columns |
| `fetchAll()` | `PDO::FETCH_GROUP` or `PDO::FETCH_UNIQUE` combined with a supported `PDO::FETCH_ASSOC`, `PDO::FETCH_NUM`, `PDO::FETCH_BOTH`, or `PDO::FETCH_COLUMN` mode |

These checks detect any duplicate name in the row, including a duplicate
unrelated to the bound or class column. They also apply to modes configured
through `setFetchMode()`, a `query()` mode argument, or the connection's
`PDO::ATTR_DEFAULT_FETCH_MODE` where supported. The check occurs when fetching
or iterating over rows, not when configuring the mode. PDO's default mode is
`PDO::FETCH_BOTH`, so a plain `fetch()` can throw.

```php
$pdo = $this->db()->pdo();
$sql = 'SELECT 11 AS id, 22 AS id';

$statement = $pdo->query($sql);
$row = $statement->fetch(PDO::FETCH_ASSOC); // ['id' => 22]

$statement = $pdo->query($sql);
$row = $statement->fetch(PDO::FETCH_OBJ); // $row->id is 22

$statement = $pdo->query($sql);
try {
    $row = $statement->fetch(PDO::FETCH_NUM);
} catch (PDOException $e) {
    // The message begins with SQLSTATE[HY000].
}

$statement = $pdo->query('SELECT 11 AS first_id, 22 AS second_id');
$row = $statement->fetch(PDO::FETCH_NUM); // [11, 22]
```

For example, alias both `id` columns in a join:

```sql
SELECT players.id AS player_id, teams.id AS team_id
FROM players JOIN teams ON teams.id = players.team_id;
```
