<?php

declare(strict_types=1);

namespace Atoms;

/**
 * The guaranteed database surface an Atom can touch. Backed per-Atom by a single
 * SQLite file in the runtime; {@see Sqlite\SqliteDatabase} is the core
 * implementation. Query-builder ergonomics are an optional bridge on top — the
 * API is just this PDO-level interface.
 */
interface Database
{
    public function pdo(): \PDO;

    /**
     * Run a SELECT (or other row-returning) statement.
     *
     * @param array<int|string, mixed> $bindings
     * @return list<array<string, mixed>> the result rows as associative arrays
     */
    public function query(string $sql, array $bindings = []): array;

    /**
     * Run a writing statement (INSERT/UPDATE/DELETE/DDL).
     *
     * @param array<int|string, mixed> $bindings
     * @return int the number of affected rows
     */
    public function execute(string $sql, array $bindings = []): int;

    /**
     * Run $fn inside a transaction, committing on success and rolling back if it
     * throws. Returns whatever $fn returns.
     *
     * @param callable(Database): mixed $fn
     */
    public function transaction(callable $fn): mixed;
}
