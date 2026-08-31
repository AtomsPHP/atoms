---
title: PDO compatibility
description: How the Atoms PDO shim differs from native pdo_sqlite.
---

The Cloudflare runtime exposes `$this->db()->pdo()` for compatible PDO-style code, but the SQL engine is a Durable Object across a PHP↔JavaScript bridge. Atoms refuses behavior it cannot reproduce honestly.

The complete case matrix is generated from a differential run against native in-guest `pdo_sqlite`. Worker conformance verifies that the published matrix is fresh. Read the [generated matrix in the repository](https://github.com/AtomsPHP/atoms/blob/main/cloudflare/docs/pdo-compatibility.md) for member-by-member results.

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
- Driver metadata that would describe the wrong SQLite binary is refused.
- Duplicate column names destroy positional information at the current wire boundary. Alias columns distinctly before using positional or grouping fetch modes.
- Whole-number REAL values can cross back as PHP integers because workerd exposes INTEGER and REAL through the same JavaScript number type.
- Wide INTEGER results must be selected with `CAST(column AS TEXT)` to preserve values above JavaScript’s safe integer range.
- Non-empty driver option arrays passed to `prepare()` are refused rather than silently ignored.

The shim’s public reflection surface is also checked against the guest’s native PDO classes. A method that appears to exist must have a deliberate implementation or a typed refusal.
