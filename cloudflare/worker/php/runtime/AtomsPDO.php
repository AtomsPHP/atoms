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
 * {@see AtomsNotSupported}. The spike's remaining leak — `quote()`,
 * `getAttribute()` and `errorInfo()` being answered by the in-memory carrier
 * connection instead of by the Durable Object — is closed here: `quote()` is
 * implemented in PHP with SQLite's own escaping rules, `errorCode()`/
 * `errorInfo()` report the bridge's last statement, and `getAttribute()` serves
 * only the three attributes that have a truthful answer.
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

    /** @var int default fetch mode handed to statements this connection makes */
    private $fetchMode = \PDO::FETCH_ASSOC;

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
        if ($options !== []) {
            throw new AtomsNotSupported(
                'PDO::prepare() with driver options',
                'There is no driver-owned statement handle for options to configure.'
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
        if ($fetchModeArgs !== []) {
            throw new AtomsNotSupported(
                'PDO::query() with fetch-mode arguments',
                'Class, callback and column-index variants are not implemented by the MVP shim.'
            );
        }

        $mode = $fetchMode === null ? $this->fetchMode : FetchMode::assertSupported($fetchMode, 'PDO::query() fetch mode');

        $statement = new AtomsStatement($this->bridge, $query, $mode);
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
        $result = $this->bridge->exec((string) $statement, [], SqlBridge::MODE_RUN);

        return $result['rows_written'];
    }

    /**
     * PDO's contract is a string, not an int — the Migrator and customer code
     * both depend on that.
     *
     * @param string|null $name
     * @return string
     */
    #[\ReturnTypeWillChange]
    public function lastInsertId($name = null)
    {
        if ($name !== null) {
            throw new AtomsNotSupported(
                'PDO::lastInsertId() with a sequence name',
                'SQLite has no sequences; call it with no argument.'
            );
        }

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
     * @param string $string
     * @param int $type
     * @return string
     */
    #[\ReturnTypeWillChange]
    public function quote($string, $type = \PDO::PARAM_STR)
    {
        switch ($type) {
            case \PDO::PARAM_NULL:
                return 'NULL';

            case \PDO::PARAM_BOOL:
                return $string ? '1' : '0';

            case \PDO::PARAM_INT:
                return (string) (int) $string;

            case \PDO::PARAM_STR:
                return "'" . str_replace("'", "''", (string) $string) . "'";
        }

        throw new AtomsNotSupported(
            sprintf('PDO::quote() with parameter type %d', $type),
            'Only PARAM_NULL, PARAM_BOOL, PARAM_INT and PARAM_STR are implemented; bind values instead of quoting them.'
        );
    }

    /**
     * @return string|null the SQLSTATE of the bridge's last statement
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

    /**
     * Only the attributes with a truthful answer are served; the rest throw
     * rather than reporting the carrier connection's values.
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
        }

        throw new AtomsNotSupported(
            sprintf('PDO::getAttribute(%d)', $attribute),
            'Only ATTR_DRIVER_NAME, ATTR_ERRMODE and ATTR_DEFAULT_FETCH_MODE describe the Atoms bridge; '
            . 'anything else would be the inert carrier connection answering.'
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

        throw new AtomsNotSupported(
            sprintf('PDO::setAttribute(%d, ...)', $attribute),
            'Only ATTR_ERRMODE and ATTR_DEFAULT_FETCH_MODE are meaningful across the Atoms bridge.'
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
