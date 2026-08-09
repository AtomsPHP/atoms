<?php

/**
 * `Atoms\Database` over the Durable Object's own SQLite (mvp-spec.md
 * §PHP-side db()).
 *
 * This is the platform-side implementation the customer's Atom actually gets
 * from `$this->db()`. It is deliberately shaped like the reference
 * `Atoms\Sqlite\SqliteDatabase`:
 *
 *  - `query()` / `execute()` prepare-by-rewriting named parameters to
 *    positional **in PHP** (the wire only carries positional bindings), tag
 *    int64 values, and call the `sql.exec` door.
 *  - `transaction()` reproduces SqliteDatabase's nesting guard verbatim in
 *    behaviour — an already-open transaction is reused rather than nested,
 *    because SQLite has no nested BEGIN and the Durable Object has no
 *    savepoints — and then drives tx.begin → $fn → tx.commit, rolling back and
 *    rethrowing on any Throwable.
 *  - `pdo()` returns the hardened {@see AtomsPDO}, sharing this object's
 *    {@see SqlBridge} so both views agree about whether a transaction is open.
 *
 * No declare(strict_types=1) — see the note in host.php.
 */

namespace Atoms\Cf;

use Atoms\Database;

final class BridgeDatabase implements Database
{
    /** @var SqlBridge */
    private $bridge;

    /** @var AtomsPDO|null lazily built; one per Atom, like one connection */
    private $pdo = null;

    public function __construct(SqlBridge $bridge)
    {
        $this->bridge = $bridge;
    }

    /**
     * The documented-leaky \PDO view of the same connection.
     */
    public function pdo(): \PDO
    {
        if ($this->pdo === null) {
            $this->pdo = new AtomsPDO($this->bridge);
        }

        return $this->pdo;
    }

    /**
     * @param array<int|string, mixed> $bindings
     * @return list<array<string, mixed>>
     */
    public function query(string $sql, array $bindings = []): array
    {
        list($rewritten, $positional) = $this->toPositional($sql, $bindings);

        $result = $this->bridge->exec($rewritten, $positional, SqlBridge::MODE_ROWS);

        return $result['rows'];
    }

    /**
     * @param array<int|string, mixed> $bindings
     */
    public function execute(string $sql, array $bindings = []): int
    {
        list($rewritten, $positional) = $this->toPositional($sql, $bindings);

        $result = $this->bridge->exec($rewritten, $positional, SqlBridge::MODE_RUN);

        return $result['rows_written'];
    }

    /**
     * @param callable(Database): mixed $fn
     */
    public function transaction(callable $fn): mixed
    {
        // Nesting guard, identical in intent to SqliteDatabase::transaction():
        // reuse an already-open outer transaction; the outer call owns
        // commit/rollback. The host enforces the same rule as defence in depth.
        if ($this->bridge->inTransaction()) {
            return $fn($this);
        }

        $this->bridge->begin();

        try {
            $result = $fn($this);
            $this->bridge->commit();

            return $result;
        } catch (\Throwable $e) {
            try {
                $this->bridge->rollback();
            } catch (\PDOException $ignored) {
                // Transaction already closed (e.g. the commit itself failed).
                // The original throwable is the one that matters.
            }

            throw $e;
        }
    }

    /**
     * @param string $sql
     * @param array<int|string, mixed> $bindings
     * @return array{0: string, 1: list<mixed>}
     */
    private function toPositional($sql, array $bindings)
    {
        return NamedParams::rewrite($sql, $bindings);
    }
}
