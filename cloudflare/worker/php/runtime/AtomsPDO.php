<?php

/**
 * The \PDO half of `db()->pdo()`, hardened from the phase-2 spike's
 * `php/pdo_shim.php` (mvp-spec.md §PHP-side db()).
 *
 * The Atoms ABI declares `Database::pdo(): \PDO`, and the real
 * `Atoms\Migrations\Migrator` drives migrations entirely through this surface
 * (`query('PRAGMA user_version')`, `beginTransaction()`, `exec()`, `commit()`,
 * `inTransaction()`, `rollBack()`), so it has to be a genuine \PDO subclass
 * rather than a duck-typed bridge.
 *
 * The rule for every member: route to the {@see SqlBridge}, or throw
 * {@see AtomsNotSupported}. `quote()` is implemented in PHP with SQLite's own
 * escaping rules; `errorCode()`/`errorInfo()` report THIS CONNECTION's own
 * last operation (M1 design §3 F-27 — a statement's failure does not leak
 * here, see {@see AtomsStatement}); `getAttribute()` serves every attribute
 * that has a truthful answer and refuses the rest, never the carrier.
 *
 * The carrier `sqlite::memory:` connection exists for one reason: \PDO's
 * constructor must run for the object to be a usable \PDO. Nothing is ever
 * executed against it.
 *
 * No declare(strict_types=1) — see the note in host.php.
 */

namespace Atoms\Cf;

class AtomsPDO extends \PDO
{
    /** The DSN of the inert carrier connection. */
    const CARRIER_DSN = 'sqlite::memory:';

    /** @var SqlBridge */
    private $bridge;

    /**
     * Default fetch mode handed to statements this connection makes (M1
     * design §3 F-30): real pdo_sqlite's ATTR_DEFAULT_FETCH_MODE is FETCH_BOTH
     * (measured), so this matches it rather than the MVP's original
     * FETCH_ASSOC. {@see BridgeDatabase} passes its own fetch mode explicitly
     * on every call, so it is unaffected by this default.
     *
     * @var int
     */
    private $fetchMode = \PDO::FETCH_BOTH;

    /**
     * This CONNECTION's own errorCode()/errorInfo() triple (M1 design §3
     * F-27): set only by operations performed directly through the
     * connection ({@see exec()}) — never by a statement's execute(), which
     * keeps its own triple on {@see AtomsStatement} instead.
     *
     * @var array{0: string, 1: int|null, 2: string|null}
     */
    private $errorInfo = [SqlBridge::SQLSTATE_OK, null, null];

    public function __construct(SqlBridge $bridge)
    {
        $this->bridge = $bridge;

        parent::__construct(self::CARRIER_DSN);
    }

    /**
     * @param string $query
     * @param array<int, mixed> $options
     * @return AtomsStatement
     */
    #[\ReturnTypeWillChange]
    public function prepare($query, $options = [])
    {
        // M1 design §3 F-15: real pdo_sqlite silently IGNORES unrecognized
        // driver options (measured: ATTR_TIMEOUT accepted and ignored) and
        // silently REFUSES CURSOR_SCROLL (prepare() returns false; sqlite has
        // no scrollable cursor). Neither of those is honest to reproduce:
        // silently ignoring an option is the exact failure mode this
        // milestone exists to delete, and returning `false` for a statement
        // we cannot honour would be a customer's next call segfaulting on a
        // bool. So exactly two option shapes are accepted — none, and the
        // one truthful cursor value — and everything else, including
        // ATTR_TIMEOUT and ATTR_STATEMENT_CLASS (we always return
        // AtomsStatement; accepting a different class would be a silent
        // lie), is refused loudly instead.
        if ($options !== [] && $options !== [\PDO::ATTR_CURSOR => \PDO::CURSOR_FWDONLY]) {
            throw new AtomsNotSupported(
                'PDO::prepare() with driver options',
                'Only an empty options array, or [ATTR_CURSOR => PDO::CURSOR_FWDONLY] (the only truthful '
                . 'cursor value), are accepted. Real pdo_sqlite silently ignores unrecognized options and '
                . 'silently refuses CURSOR_SCROLL; this runtime refuses both rather than silently ignoring '
                . 'or mis-answering one.'
            );
        }

        return new AtomsStatement($this->bridge, $query, $this->fetchMode);
    }

    /**
     * @param string $query
     * @param int|null $fetchMode
     * @param mixed ...$fetchModeArgs
     * @return AtomsStatement
     */
    #[\ReturnTypeWillChange]
    public function query($query, $fetchMode = null, ...$fetchModeArgs)
    {
        $statement = new AtomsStatement($this->bridge, $query, $this->fetchMode);

        // M1 design §3 F-14: query()'s fetch-mode arguments are exactly
        // setFetchMode()'s — set the mode BEFORE execute() so an invalid
        // combination is rejected before anything runs, same as a customer
        // calling setFetchMode() themselves.
        if ($fetchMode !== null) {
            $statement->setFetchMode($fetchMode, ...$fetchModeArgs);
        }

        $statement->execute();

        return $statement;
    }

    /**
     * @param string $statement
     * @return int
     */
    #[\ReturnTypeWillChange]
    public function exec($statement)
    {
        try {
            $result = $this->bridge->exec((string) $statement, [], SqlBridge::MODE_RUN);
        } catch (\PDOException $e) {
            $this->errorInfo = is_array($e->errorInfo) && $e->errorInfo !== []
                ? $e->errorInfo
                : ['HY000', null, $e->getMessage()];
            throw $e;
        }

        $this->errorInfo = [SqlBridge::SQLSTATE_OK, null, null];

        return $result['rows_written'];
    }

    /**
     * PDO's contract is a string, not an int — the Migrator and customer code
     * both depend on that. Real pdo_sqlite IGNORES a given sequence name
     * (SQLite has none) rather than refusing it (measured, design §3 F-20);
     * our former throw was stricter than the driver we claim to be, for no
     * truth gained.
     *
     * @param string|null $name
     * @return string
     */
    #[\ReturnTypeWillChange]
    public function lastInsertId($name = null)
    {
        return $this->bridge->lastInsertId();
    }

    /**
     * @return bool
     */
    #[\ReturnTypeWillChange]
    public function beginTransaction()
    {
        $this->bridge->begin();

        return true;
    }

    /**
     * @return bool
     */
    #[\ReturnTypeWillChange]
    public function commit()
    {
        $this->bridge->commit();

        return true;
    }

    /**
     * @return bool
     */
    #[\ReturnTypeWillChange]
    public function rollBack()
    {
        $this->bridge->rollback();

        return true;
    }

    /**
     * @return bool
     */
    #[\ReturnTypeWillChange]
    public function inTransaction()
    {
        return $this->bridge->inTransaction();
    }

    /**
     * SQLite escaping, implemented here rather than delegated to the carrier
     * connection, so that nothing about this object depends on the carrier.
     *
     * M1 design §3 F-24 (measured): real pdo_sqlite IGNORES `$type` entirely
     * — `quote(null, PARAM_NULL)` is `"''"` (not the string `NULL`),
     * `quote(true, PARAM_BOOL)` is `"'1'"` (quoted, not bare `1`),
     * `quote('42', PARAM_INT)` is `"'42'"` (quoted, not bare `42`), and an
     * unrecognized `$type` is not refused. Every branch the old
     * type-dispatching implementation had was a divergence. One exception,
     * deliberately NOT matched: real pdo_sqlite silently TRUNCATES a value at
     * its first NUL byte; this runtime refuses instead, because silently
     * truncating a value is the one thing this surface must not do (pinned
     * deviation `pdo.quote.nul_byte`).
     *
     * @param string $string
     * @param int $type
     * @return string
     */
    #[\ReturnTypeWillChange]
    public function quote($string, $type = \PDO::PARAM_STR)
    {
        $s = (string) $string;

        if (strpos($s, "\0") !== false) {
            throw new \PDOException(
                'Atoms: PDO::quote() refuses a value containing a NUL byte. Real pdo_sqlite silently '
                . 'truncates the value at the NUL instead, which this runtime will not reproduce — encode '
                . 'binary or NUL-bearing data (e.g. base64) before quoting it.'
            );
        }

        return "'" . str_replace("'", "''", $s) . "'";
    }

    /**
     * @return string|null the SQLSTATE of this connection's last direct operation
     */
    #[\ReturnTypeWillChange]
    public function errorCode()
    {
        return $this->errorInfo[0];
    }

    /**
     * @return array{0: string, 1: int|null, 2: string|null}
     */
    #[\ReturnTypeWillChange]
    public function errorInfo()
    {
        return $this->errorInfo;
    }

    /**
     * Only the attributes with a truthful answer are served; the rest throw
     * rather than reporting the carrier connection's values.
     *
     * M1 design §3 F-22 (measured): `ATTR_PERSISTENT` is always `false`,
     * `ATTR_CASE`'s default is `CASE_NATURAL`, `ATTR_ORACLE_NULLS`'s default
     * is `NULL_NATURAL` — each a permanent truth about this runtime that
     * matches real pdo_sqlite exactly. `ATTR_SERVER_VERSION` /
     * `ATTR_CLIENT_VERSION` stay refused permanently: the guest's SQLite and
     * the Durable Object's SQLite are different builds, so any answer would
     * be a lie about which one, and there is no client library on this side
     * of the wire either.
     *
     * @param int $attribute
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function getAttribute($attribute)
    {
        switch ($attribute) {
            case \PDO::ATTR_DRIVER_NAME:
                return 'sqlite';

            case \PDO::ATTR_ERRMODE:
                return \PDO::ERRMODE_EXCEPTION;

            case \PDO::ATTR_DEFAULT_FETCH_MODE:
                return $this->fetchMode;

            case \PDO::ATTR_PERSISTENT:
                return false;

            case \PDO::ATTR_CASE:
                return \PDO::CASE_NATURAL;

            case \PDO::ATTR_ORACLE_NULLS:
                return \PDO::NULL_NATURAL;
        }

        throw new AtomsNotSupported(
            sprintf('PDO::getAttribute(%d)', $attribute),
            'Only ATTR_DRIVER_NAME, ATTR_ERRMODE, ATTR_DEFAULT_FETCH_MODE, ATTR_PERSISTENT, ATTR_CASE and '
            . 'ATTR_ORACLE_NULLS describe the Atoms bridge; anything else (including the two version '
            . 'attributes, which would have to answer for one of two different SQLite builds) would be the '
            . 'inert carrier connection answering.'
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
        if ($attribute === \PDO::ATTR_ERRMODE) {
            if ($value === \PDO::ERRMODE_EXCEPTION) {
                return true;
            }

            throw new AtomsNotSupported(
                'PDO::ATTR_ERRMODE other than ERRMODE_EXCEPTION',
                'The bridge reports every failure by throwing; silent and warning modes cannot be honoured.'
            );
        }

        if ($attribute === \PDO::ATTR_DEFAULT_FETCH_MODE) {
            $this->fetchMode = FetchMode::assertSupported($value, 'PDO::ATTR_DEFAULT_FETCH_MODE');

            return true;
        }

        // M1 design §3 F-22: only the NATURAL value is accepted for these
        // two — setting a real one would require actually reshaping every
        // fetch (upper/lower-casing keys, NULL<->'' conversion), which is a
        // capability this runtime does not have, so the honest answer to
        // "make it non-natural" is a refusal, not a silent no-op.
        if ($attribute === \PDO::ATTR_CASE) {
            if ($value === \PDO::CASE_NATURAL) {
                return true;
            }

            throw new AtomsNotSupported(
                'PDO::ATTR_CASE other than CASE_NATURAL',
                'Column-name case folding is not implemented; fetched keys are always the query\'s own case.'
            );
        }

        if ($attribute === \PDO::ATTR_ORACLE_NULLS) {
            if ($value === \PDO::NULL_NATURAL) {
                return true;
            }

            throw new AtomsNotSupported(
                'PDO::ATTR_ORACLE_NULLS other than NULL_NATURAL',
                'Empty-string/NULL conversion on fetch is not implemented; values are always the column\'s own.'
            );
        }

        throw new AtomsNotSupported(
            sprintf('PDO::setAttribute(%d, ...)', $attribute),
            'Only ATTR_ERRMODE, ATTR_DEFAULT_FETCH_MODE, ATTR_CASE and ATTR_ORACLE_NULLS are meaningful '
            . 'across the Atoms bridge.'
        );
    }

    /**
     * The one parent static PHP permits redeclaring in a subclass and that
     * has a truthful answer to give (M1 design §0.2a, §3 F-25): the question
     * "which PDO drivers does this PHP build carry?" is about the BUILD, not
     * about a connection, so answering it from the real parent — rather than
     * leaving it undeclared, where it would fall through to whatever the
     * inert carrier connection happens to expose — is honest. Declaring it
     * also keeps the reflection tripwire's allowlist at one entry: this was
     * the only member of \PDO's public surface a subclass could reach but
     * had not (see SurfaceAudit rule R2).
     *
     * @return list<string>
     */
    public static function getAvailableDrivers(): array
    {
        return \PDO::getAvailableDrivers();
    }

    // ---------------------------------------------------------------------
    // Driver extensions. These are provided by pdo_sqlite on the carrier
    // connection and would otherwise appear to work while affecting nothing
    // that the Durable Object executes.
    // ---------------------------------------------------------------------

    /**
     * @param string $function
     * @param callable $callback
     * @param int $numArgs
     * @param int $flags
     * @return bool
     */
    #[\ReturnTypeWillChange]
    public function sqliteCreateFunction($function, $callback, $numArgs = -1, $flags = 0)
    {
        throw new AtomsNotSupported(
            'PDO::sqliteCreateFunction()',
            'Statements execute in the Durable Object, not in the guest, so a PHP callback cannot be registered with them.'
        );
    }

    /**
     * @param string $function
     * @param callable $stepCallback
     * @param callable $finalizeCallback
     * @param int $numArgs
     * @return bool
     */
    #[\ReturnTypeWillChange]
    public function sqliteCreateAggregate($function, $stepCallback, $finalizeCallback, $numArgs = -1)
    {
        throw new AtomsNotSupported(
            'PDO::sqliteCreateAggregate()',
            'Statements execute in the Durable Object, not in the guest, so a PHP callback cannot be registered with them.'
        );
    }

    /**
     * @param string $name
     * @param callable $callback
     * @return bool
     */
    #[\ReturnTypeWillChange]
    public function sqliteCreateCollation($name, $callback)
    {
        throw new AtomsNotSupported(
            'PDO::sqliteCreateCollation()',
            'Statements execute in the Durable Object, not in the guest, so a PHP callback cannot be registered with them.'
        );
    }
}
