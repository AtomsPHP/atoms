<?php

/**
 * The single owner of the SQL seam: every statement and every transaction
 * boundary in the guest goes through exactly one of these.
 *
 * Both {@see BridgeDatabase} (the Atoms\Database implementation) and
 * {@see AtomsPDO} / {@see AtomsStatement} (the leaky-but-real \PDO surface)
 * hold the SAME instance, which is why `Database::transaction()`'s nesting
 * guard and `PDO::inTransaction()` observe one shared truth — exactly as they
 * do on the platform runtime, where both are views of one PDO connection.
 *
 * Statements run on `ctx.storage.sql` in the Durable Object. While a
 * transaction is open the host is parked inside
 * `ctx.storage.transactionSync(cb)`, so these same sync `sql.exec` calls land
 * inside the callback's scope (read-your-own-writes).
 *
 * No declare(strict_types=1) — see the note in host.php.
 */

namespace Atoms\Cf;

final class SqlBridge
{
    /** Row-returning execution mode for sql.exec. */
    const MODE_ROWS = 'rows';

    /** Counters-only execution mode for sql.exec. */
    const MODE_RUN = 'run';

    /** SQLSTATE reported when nothing has failed yet. */
    const SQLSTATE_OK = '00000';

    /** @var bool whether the host is currently parked inside transactionSync */
    private $inTransaction = false;

    /** @var int rowid of the most recent insert, 0 if there has been none */
    private $lastInsertRowid = 0;

    /** @var array{0: string, 1: int|null, 2: string|null} PDO-shaped errorInfo triple */
    private $errorInfo = [self::SQLSTATE_OK, null, null];

    /**
     * Run one statement against the Durable Object's SQLite.
     *
     * @param string $sql
     * @param list<mixed> $bindings positional only; named params are rewritten
     *                              to positional by the caller, in PHP
     * @param string $mode self::MODE_ROWS | self::MODE_RUN
     * @return array{rows: list<array<string, mixed>>, rows_written: int, last_insert_rowid: int}
     * @throws \PDOException on a SQL error, with a real errorInfo() triple
     */
    public function exec($sql, array $bindings, $mode = self::MODE_ROWS)
    {
        $request = [
            'op' => 'sql.exec',
            'sql' => (string) $sql,
            'bindings' => int64_encode($this->normalizeBindings($bindings)),
            'mode' => $mode,
        ];

        $reply = host_sync_raw($request);

        if ($reply['ok'] !== true) {
            throw $this->failure($reply);
        }

        $this->errorInfo = [self::SQLSTATE_OK, null, null];

        $rows = [];
        if (isset($reply['rows']) && is_array($reply['rows'])) {
            /** @var list<array<string, mixed>> $rows */
            $rows = int64_decode($reply['rows']);
        }

        // The host reports `last_insert_rowid` only for a statement that actually
        // wrote; the key is ABSENT for reads and for intercepted pragmas. That
        // absence is meaningful and must not be read as "0": PDO's contract is
        // that lastInsertId() keeps reporting the last insert across any number
        // of intervening SELECTs, so the cached value is left alone here.
        if (array_key_exists('last_insert_rowid', $reply)) {
            $rowid = int64_decode($reply['last_insert_rowid']);
            if (is_int($rowid)) {
                $this->lastInsertRowid = $rowid;
            }
        }

        return [
            'rows' => $rows,
            'rows_written' => isset($reply['rows_written']) ? (int) $reply['rows_written'] : 0,
            'last_insert_rowid' => $this->lastInsertRowid,
        ];
    }

    /**
     * Open a transaction: the host enters `ctx.storage.transactionSync(cb)` and
     * resumes the guest from inside `cb`.
     *
     * @throws \PDOException when one is already open
     */
    public function begin()
    {
        if ($this->inTransaction) {
            throw new \PDOException('There is already an active transaction.');
        }

        host_park(['op' => 'tx.begin']);
        $this->inTransaction = true;
    }

    /**
     * Commit: park so the host's `cb` can return, which is what commits.
     *
     * @throws \PDOException when none is open
     */
    public function commit()
    {
        $this->assertInTransaction('commit');

        try {
            host_park(['op' => 'tx.commit']);
        } finally {
            // Whatever happened, the guest is no longer inside cb. Leaving the
            // flag set would wedge every later transaction.
            $this->inTransaction = false;
        }
    }

    /**
     * Roll back: park so the host can throw its sentinel inside `cb`, which is
     * what makes Cloudflare discard the write set.
     *
     * @throws \PDOException when none is open
     */
    public function rollback()
    {
        $this->assertInTransaction('roll back');

        try {
            host_park(['op' => 'tx.rollback']);
        } finally {
            $this->inTransaction = false;
        }
    }

    /**
     * @return bool
     */
    public function inTransaction()
    {
        return $this->inTransaction;
    }

    /**
     * PDO contract: a string, never an int.
     *
     * @return string
     */
    public function lastInsertId()
    {
        return (string) $this->lastInsertRowid;
    }

    /**
     * @return string the SQLSTATE of the most recent statement
     */
    public function errorCode()
    {
        return $this->errorInfo[0];
    }

    /**
     * @return array{0: string, 1: int|null, 2: string|null}
     */
    public function errorInfo()
    {
        return $this->errorInfo;
    }

    /**
     * @param string $what
     * @throws \PDOException
     */
    private function assertInTransaction($what)
    {
        if (!$this->inTransaction) {
            throw new \PDOException(sprintf('There is no active transaction to %s.', $what));
        }
    }

    /**
     * Build the exception for an `ok: false` sql.exec reply and record the
     * errorInfo triple that PDO consumers will read afterwards.
     *
     * @param array<string, mixed> $reply
     * @return \PDOException
     */
    private function failure(array $reply)
    {
        $error = isset($reply['error']) && is_array($reply['error']) ? $reply['error'] : [];
        $code = isset($error['code']) ? (string) $error['code'] : 'sql_error';
        $message = isset($error['message']) ? (string) $error['message'] : 'Unknown SQL error.';
        $sqlstate = isset($error['sqlstate']) ? (string) $error['sqlstate'] : 'HY000';

        // 1 == SQLITE_ERROR. The bridge has no finer driver code to report, so
        // the triple stays shaped like PDO's without inventing detail.
        $this->errorInfo = [$sqlstate, 1, $message];

        $exception = new \PDOException(sprintf('SQLSTATE[%s] [%s] %s', $sqlstate, $code, $message));
        $exception->errorInfo = $this->errorInfo;

        return $exception;
    }

    /**
     * Coerce bindings into what the wire (and `ctx.storage.sql`) accepts.
     *
     * @param list<mixed> $bindings
     * @return list<mixed>
     * @throws \PDOException on a value that cannot be bound
     */
    private function normalizeBindings(array $bindings)
    {
        $out = [];

        foreach (array_values($bindings) as $index => $value) {
            if ($value === null || is_int($value) || is_float($value) || is_string($value)) {
                $out[] = $value;
                continue;
            }

            if (is_bool($value)) {
                // SQLite has no boolean type; PDO binds them as 0/1.
                $out[] = $value ? 1 : 0;
                continue;
            }

            throw new \PDOException(sprintf(
                'SQLSTATE[HY105] Invalid parameter type: binding %d is a %s; '
                . 'only null, bool, int, float and string cross the Atoms SQL bridge.',
                $index,
                is_object($value) ? get_class($value) : gettype($value)
            ));
        }

        return $out;
    }
}
