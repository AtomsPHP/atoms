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

    /**
     * Run one statement against the Durable Object's SQLite.
     *
     * Deliberately holds no error-state memory of its own: real PDO
     * scopes `errorCode()`/`errorInfo()` to the HANDLE that ran the
     * failing operation — a statement's own failure does not leak
     * onto the connection's error state (measured: after a statement
     * `execute()` fails with a UNIQUE violation, `$stmt->errorCode()` is
     * `'23000'` while `$pdo->errorCode()` is still `'00000'`). This bridge is
     * the ONE instance shared by {@see AtomsPDO} (connection-level ops) and
     * every {@see AtomsStatement} it prepares, so it cannot be where that
     * state lives without conflating the two. Each of those two classes
     * caches its OWN triple, populated only from ITS OWN calls into this
     * method; this method just runs the RPC and either returns the result or
     * throws {@see BridgeSqlException} with the failure's own triple on it.
     *
     * @param string $sql
     * @param list<mixed> $bindings positional only; named params are rewritten
     *                              to positional by the caller, in PHP
     * @param string $mode self::MODE_ROWS | self::MODE_RUN
     * @return array{rows: list<array<string, mixed>>, columns: list<string>, rows_written: int, last_insert_rowid: int}
     * @throws BridgeSqlException on a SQL error, with a real errorInfo() triple
     */
    public function exec($sql, array $bindings, $mode = self::MODE_ROWS)
    {
        $request = [
            'op' => 'sql.exec',
            'sql' => (string) $sql,
            'bindings' => self::tagIntBindings($this->normalizeBindings($bindings)),
            'mode' => $mode,
        ];

        $reply = host_sync_raw($request);

        if ($reply['ok'] !== true) {
            throw self::failure($reply);
        }

        $rows = [];
        if (isset($reply['rows']) && is_array($reply['rows'])) {
            /** @var list<array<string, mixed>> $rows */
            $rows = int64_decode($reply['rows']);
        }

        // Branch A: source-order column names, duplicates
        // preserved, from `cursor.columnNames` (bridge.js). Absent (an empty
        // list) for a non-"rows" reply. This is the only place the wire's
        // arity survives — the `{column: value}` row maps have already
        // collapsed duplicate names — so it is what lets AtomsStatement
        // detect duplicates and refuse precisely instead of guessing.
        $columns = [];
        if (isset($reply['columns']) && is_array($reply['columns'])) {
            foreach ($reply['columns'] as $name) {
                $columns[] = (string) $name;
            }
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
            'columns' => $columns,
            'rows_written' => isset($reply['rows_written']) ? (int) $reply['rows_written'] : 0,
            'last_insert_rowid' => $this->lastInsertRowid,
        ];
    }

    /**
     * Tag EVERY int binding with the int64 wire tag, not only those outside
     * JSON's safe range. MEASURED:
     * `ctx.storage.sql` binds a plain JS `number` — including an integral
     * one — with SQLite storage class REAL, never INTEGER (`typeof(?)` on a
     * bound `42` reported `'real'`). The int64 tag is already how a wide
     * integer crosses to be inlined as a validated decimal literal
     * (`inlineWideIntegers()`, src/int64.js); tagging every int, not only
     * wide ones, routes every genuinely-integer SQL binding through that
     * same literal-inlining path instead of a parameter bind, so SQLite
     * parses an actual integer literal and reports INTEGER storage class.
     * This is scoped to SQL bindings only — it deliberately does NOT touch
     * the general {@see int64_encode()} used for method args/results
     * elsewhere on the wire, so nothing outside `sql.exec` changes shape.
     *
     * @param list<mixed> $bindings already normalized: null|int|float|string only
     * @return list<mixed>
     */
    private static function tagIntBindings(array $bindings)
    {
        $out = [];
        foreach ($bindings as $value) {
            $out[] = is_int($value) ? [INT64_TAG => (string) $value] : $value;
        }

        return $out;
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
     * Build the exception for an `ok: false` sql.exec reply, with the
     * errorInfo triple attached AND settable as ->getCode() — the caller
     * (AtomsPDO or AtomsStatement) is what decides whether the triple
     * becomes ITS cached error state (per-handle error scoping).
     *
     * The raw `$error` object — everything
     * `bridge.js`'s `fail()` spread into `reply.error`, e.g. `cap`/`limit`
     * for `sql_result_too_large` — is passed through as the exception's
     * `detail` ({@see BridgeSqlException::getDetail()}) instead of being
     * discarded here after only `code`/`message`/`sqlstate` are read out
     * of it.
     *
     * @param array<string, mixed> $reply
     * @return BridgeSqlException
     */
    private static function failure(array $reply)
    {
        $error = isset($reply['error']) && is_array($reply['error']) ? $reply['error'] : [];
        $code = isset($error['code']) ? (string) $error['code'] : 'sql_error';
        $message = isset($error['message']) ? (string) $error['message'] : 'Unknown SQL error.';
        $sqlstate = isset($error['sqlstate']) ? (string) $error['sqlstate'] : 'HY000';

        // 1 == SQLITE_ERROR. The bridge has no finer driver code to report, so
        // the triple stays shaped like PDO's without inventing detail.
        $errorInfo = [$sqlstate, 1, $message];

        return new BridgeSqlException(sprintf('SQLSTATE[%s] [%s] %s', $sqlstate, $code, $message), $errorInfo, $error);
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
