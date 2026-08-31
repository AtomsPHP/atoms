-- Probe's schema (design §2.2). This file is single-sourced: the
-- Durable Object side applies it through the real Atoms\Migrations\Migrator
-- (one sql.exec over the whole text, same as every other fixture's
-- migration); the differential harness's comparator reads
-- this same file and splits it on ';' to replay it against a native
-- pdo_sqlite connection. Two consequences of that single-sourcing, recorded
-- here rather than in code:
--   1. No semicolons inside string literals in this file — the naive
--      splitter is the price of there being exactly one copy of the DDL,
--      and this is a fixture we control.
--   2. PDO::exec() on sqlite runs only the first statement of a
--      multi-statement string, which is why the split exists at all; the
--      Durable Object side has no such limitation.

CREATE TABLE probe_rows (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    k  TEXT NOT NULL UNIQUE,
    i  INTEGER,
    r  REAL,
    s  TEXT,
    n  TEXT
);

CREATE TABLE probe_wide (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    k  TEXT NOT NULL UNIQUE,
    v  INTEGER NOT NULL
);

CREATE TABLE probe_bulk (
    id      INTEGER PRIMARY KEY AUTOINCREMENT,
    payload TEXT NOT NULL
);
