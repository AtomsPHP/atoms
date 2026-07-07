<?php

declare(strict_types=1);

namespace Atoms\Sqlite;

use Atoms\Database;

/**
 * The core {@see Database} implementation: a thin, framework-free wrapper over a
 * PDO SQLite connection. One file per Atom in the runtime.
 */
final class SqliteDatabase implements Database
{
    public function __construct(
        private readonly \PDO $pdo,
    ) {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
    }

    /**
     * Open (creating if needed) the SQLite file at $path with the Atoms pragmas:
     * WAL journalling (skipped for :memory:), synchronous=NORMAL, a 5s busy
     * timeout, and foreign-key enforcement on. Parent directories are created.
     */
    public static function open(string $path): self
    {
        $memory = $path === ':memory:';

        if (!$memory) {
            $dir = \dirname($path);
            if ($dir !== '' && !is_dir($dir)) {
                if (!@mkdir($dir, 0o777, true) && !is_dir($dir)) {
                    throw new \RuntimeException("Could not create directory {$dir} for SQLite database.");
                }
            }
        }

        $pdo = new \PDO('sqlite:' . $path);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        if (!$memory) {
            $pdo->exec('PRAGMA journal_mode=WAL');
        }
        $pdo->exec('PRAGMA synchronous=NORMAL');
        $pdo->exec('PRAGMA busy_timeout=5000');
        $pdo->exec('PRAGMA foreign_keys=ON');

        return new self($pdo);
    }

    public function pdo(): \PDO
    {
        return $this->pdo;
    }

    public function query(string $sql, array $bindings = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($this->normalizeBindings($bindings));

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);

        return $rows;
    }

    public function execute(string $sql, array $bindings = []): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($this->normalizeBindings($bindings));

        return $statement->rowCount();
    }

    public function transaction(callable $fn): mixed
    {
        // Nesting guard: reuse an already-open outer transaction rather than
        // nesting (SQLite has no nested BEGIN). The outer call owns commit/rollback.
        if ($this->pdo->inTransaction()) {
            return $fn($this);
        }

        $this->pdo->beginTransaction();

        try {
            $result = $fn($this);
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            try {
                $this->pdo->rollBack();
            } catch (\PDOException) {
                // Transaction already closed (e.g. commit itself failed).
            }

            throw $e;
        }
    }

    /**
     * PDO positional binding requires a list; named bindings pass through.
     *
     * @param array<int|string, mixed> $bindings
     * @return array<int|string, mixed>
     */
    private function normalizeBindings(array $bindings): array
    {
        if (array_is_list($bindings)) {
            return $bindings;
        }

        foreach ($bindings as $key => $_) {
            if (!is_int($key)) {
                return $bindings; // named bindings pass through
            }
        }

        return array_values($bindings); // reindex sparse positional bindings
    }
}
