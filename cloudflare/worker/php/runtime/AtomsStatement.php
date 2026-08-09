<?php

/**
 * The \PDOStatement half of the documented-leaky `db()->pdo()` surface,
 * hardened from the phase-2 spike's `php/pdo_shim.php`.
 *
 * The Atoms customer ABI declares `Database::pdo(): \PDO`, so the object handed
 * to customer code must genuinely be a \PDO / \PDOStatement — hence the
 * subclass. No driver backs this statement: every member either routes to the
 * {@see SqlBridge} (and therefore to `ctx.storage.sql` in the Durable Object)
 * or throws {@see AtomsNotSupported}. Nothing is ever answered by the carrier
 * connection, and nothing silently no-ops.
 *
 * Known leak, documented rather than papered over: `$stmt->queryString` is set
 * on a best-effort basis only — PDO treats it as a read-only driver property,
 * so on builds that reject the write it stays uninitialized.
 *
 * No declare(strict_types=1) — see the note in host.php.
 */

namespace Atoms\Cf;

class AtomsStatement extends \PDOStatement
{
    /** @var SqlBridge */
    private $bridge;

    /** @var string the SQL as prepared, with `:name` placeholders intact */
    private $sql;

    /** @var array<int|string, mixed> values bound with bindValue() */
    private $bound = [];

    /** @var list<array<string, mixed>> rows from the most recent execute() */
    private $rows = [];

    /** @var int cursor into $rows */
    private $cursor = 0;

    /** @var int rows_written reported by the most recent execute() */
    private $rowsWritten = 0;

    /** @var int one of the PDO::FETCH_* modes this shim supports */
    private $fetchMode;

    /**
     * @param SqlBridge $bridge
     * @param string $sql
     * @param int $fetchMode default fetch mode inherited from the AtomsPDO
     */
    public function __construct(SqlBridge $bridge, $sql, $fetchMode = \PDO::FETCH_ASSOC)
    {
        $this->bridge = $bridge;
        $this->sql = (string) $sql;
        $this->fetchMode = FetchMode::assertSupported($fetchMode, 'PDOStatement fetch mode');

        try {
            // Best effort: PDO exposes queryString as a driver-owned read-only
            // property, so this write is allowed to fail.
            $this->queryString = $this->sql;
        } catch (\Throwable $ignored) {
        }
    }

    /**
     * @param array<int|string, mixed>|null $params
     * @return bool
     */
    public function execute(?array $params = null): bool
    {
        // PDO semantics: an array passed to execute() replaces bound values.
        $bindings = $params === null ? $this->bound : $params;

        list($sql, $positional) = NamedParams::rewrite($this->sql, $bindings);

        $result = $this->bridge->exec($sql, $positional, SqlBridge::MODE_ROWS);

        $this->rows = $result['rows'];
        $this->rowsWritten = $result['rows_written'];
        $this->cursor = 0;

        return true;
    }

    /**
     * @param int|string $param 1-based position or `:name` / `name`
     * @param mixed $value
     * @param int $type
     * @return bool
     */
    public function bindValue($param, $value, $type = \PDO::PARAM_STR): bool
    {
        if ($type === \PDO::PARAM_LOB) {
            throw new AtomsNotSupported(
                'PDO::PARAM_LOB',
                'Binary values do not cross the MVP JSON bridge; store them base64-encoded as text.'
            );
        }

        $this->bound[$param] = $value;

        return true;
    }

    /**
     * @param int $mode
     * @param int $cursorOrientation
     * @param int $cursorOffset
     * @return mixed the row, or false when the cursor is exhausted
     */
    #[\ReturnTypeWillChange]
    public function fetch($mode = \PDO::FETCH_DEFAULT, $cursorOrientation = \PDO::FETCH_ORI_NEXT, $cursorOffset = 0)
    {
        if ($cursorOrientation !== \PDO::FETCH_ORI_NEXT) {
            throw new AtomsNotSupported(
                'PDOStatement::fetch() with a scrollable cursor',
                'The bridge buffers the whole result set; only FETCH_ORI_NEXT is available.'
            );
        }

        $mode = $this->resolveMode($mode);

        if (!array_key_exists($this->cursor, $this->rows)) {
            return false;
        }

        $row = $this->rows[$this->cursor];
        $this->cursor++;

        return FetchMode::shape($row, $mode);
    }

    /**
     * @param int $mode
     * @param mixed ...$args
     * @return array<int|string, mixed>
     */
    #[\ReturnTypeWillChange]
    public function fetchAll($mode = \PDO::FETCH_DEFAULT, ...$args)
    {
        if ($args !== []) {
            throw new AtomsNotSupported(
                'PDOStatement::fetchAll() with fetch-mode arguments',
                'Class, callback and column-index variants are not implemented by the MVP shim.'
            );
        }

        $mode = $this->resolveMode($mode);
        $remaining = array_slice($this->rows, $this->cursor);
        $this->cursor = count($this->rows);

        if ($mode === \PDO::FETCH_COLUMN) {
            $out = [];
            foreach ($remaining as $row) {
                $values = array_values($row);
                $out[] = array_key_exists(0, $values) ? $values[0] : null;
            }

            return $out;
        }

        if ($mode === \PDO::FETCH_KEY_PAIR) {
            $out = [];
            foreach ($remaining as $row) {
                $values = array_values($row);
                if (count($values) < 2) {
                    throw new \PDOException(
                        'SQLSTATE[HY000] PDO::FETCH_KEY_PAIR requires exactly two columns.'
                    );
                }
                $out[$values[0]] = $values[1];
            }

            return $out;
        }

        $out = [];
        foreach ($remaining as $row) {
            $out[] = FetchMode::shape($row, $mode);
        }

        return $out;
    }

    /**
     * @param int $column 0-based column index
     * @return mixed the value, or false when the cursor is exhausted
     */
    #[\ReturnTypeWillChange]
    public function fetchColumn($column = 0)
    {
        if (!array_key_exists($this->cursor, $this->rows)) {
            return false;
        }

        $values = array_values($this->rows[$this->cursor]);
        $this->cursor++;

        return array_key_exists($column, $values) ? $values[$column] : null;
    }

    /**
     * PDO's contract: affected rows for INSERT/UPDATE/DELETE, and undefined
     * (SQLite reports 0) for SELECT. This reports what the host counted.
     */
    public function rowCount(): int
    {
        return $this->rowsWritten;
    }

    public function columnCount(): int
    {
        if (!array_key_exists(0, $this->rows)) {
            return 0;
        }

        return count($this->rows[0]);
    }

    public function closeCursor(): bool
    {
        $this->rows = [];
        $this->cursor = 0;

        return true;
    }

    /**
     * @param int $mode
     * @param mixed ...$args
     * @return bool
     */
    #[\ReturnTypeWillChange]
    public function setFetchMode($mode, ...$args)
    {
        if ($args !== []) {
            throw new AtomsNotSupported(
                'PDOStatement::setFetchMode() with mode arguments',
                'Class, object and column-bound fetch modes are not implemented by the MVP shim.'
            );
        }

        $this->fetchMode = FetchMode::assertSupported($mode, 'PDOStatement::setFetchMode()');

        return true;
    }

    /**
     * Buffered result set, so foreach over the statement is exact.
     */
    public function getIterator(): \Iterator
    {
        return new \ArrayIterator($this->fetchAll($this->fetchMode));
    }

    /**
     * @return string|null
     */
    #[\ReturnTypeWillChange]
    public function errorCode()
    {
        return $this->bridge->errorCode();
    }

    /**
     * @return array{0: string, 1: int|null, 2: string|null}
     */
    #[\ReturnTypeWillChange]
    public function errorInfo()
    {
        return $this->bridge->errorInfo();
    }

    // ---------------------------------------------------------------------
    // Deliberately unsupported. Each throws; none of them silently no-ops or
    // falls through to a carrier connection.
    // ---------------------------------------------------------------------

    /**
     * @param int|string $param
     * @param mixed $var
     * @param int $type
     * @param int $maxLength
     * @param mixed $driverOptions
     * @return bool
     */
    #[\ReturnTypeWillChange]
    public function bindParam($param, &$var, $type = \PDO::PARAM_STR, $maxLength = 0, $driverOptions = null)
    {
        throw new AtomsNotSupported(
            'PDOStatement::bindParam()',
            'By-reference binding needs a driver-owned statement handle; use bindValue() or pass values to execute().'
        );
    }

    /**
     * @param int|string $column
     * @param mixed $var
     * @param int $type
     * @param int $maxLength
     * @param mixed $driverOptions
     * @return bool
     */
    #[\ReturnTypeWillChange]
    public function bindColumn($column, &$var, $type = \PDO::PARAM_STR, $maxLength = 0, $driverOptions = null)
    {
        throw new AtomsNotSupported(
            'PDOStatement::bindColumn()',
            'PDO::FETCH_BOUND is not implemented by the MVP shim.'
        );
    }

    /**
     * @param string|null $class
     * @param array<int, mixed>|null $constructorArgs
     * @return object|false
     */
    #[\ReturnTypeWillChange]
    public function fetchObject($class = 'stdClass', $constructorArgs = null)
    {
        throw new AtomsNotSupported(
            'PDOStatement::fetchObject()',
            'Hydrating arbitrary classes from a row is not implemented; fetch with PDO::FETCH_OBJ or PDO::FETCH_ASSOC.'
        );
    }

    /**
     * @param int $name
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function getAttribute($name)
    {
        throw new AtomsNotSupported(
            'PDOStatement::getAttribute()',
            'There is no driver-owned statement handle to read attributes from.'
        );
    }

    /**
     * @param int $attribute
     * @param mixed $value
     * @return bool
     */
    #[\ReturnTypeWillChange]
    public function setAttribute($attribute, $value)
    {
        throw new AtomsNotSupported(
            'PDOStatement::setAttribute()',
            'There is no driver-owned statement handle to configure.'
        );
    }

    /**
     * @param int $column
     * @return array<string, mixed>|false
     */
    #[\ReturnTypeWillChange]
    public function getColumnMeta($column)
    {
        throw new AtomsNotSupported(
            'PDOStatement::getColumnMeta()',
            'The sql.exec reply carries values only, not column metadata.'
        );
    }

    /**
     * @return bool
     */
    #[\ReturnTypeWillChange]
    public function nextRowset()
    {
        throw new AtomsNotSupported(
            'PDOStatement::nextRowset()',
            'The bridge returns a single result set per statement.'
        );
    }

    /**
     * @return void
     */
    #[\ReturnTypeWillChange]
    public function debugDumpParams()
    {
        throw new AtomsNotSupported(
            'PDOStatement::debugDumpParams()',
            'It writes to stdout, which is the Durable Object response body in this runtime.'
        );
    }

    /**
     * Resolve PDO::FETCH_DEFAULT against this statement's configured mode.
     *
     * @param int $mode
     * @return int
     */
    private function resolveMode($mode)
    {
        if ($mode === \PDO::FETCH_DEFAULT) {
            return $this->fetchMode;
        }

        return FetchMode::assertSupported($mode, 'PDOStatement fetch mode');
    }
}
