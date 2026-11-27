<?php

namespace Atoms\Cf;

/**
 * Guest entry point for the statically linked pdo_atoms prototype. All handles
 * opened for an Atom share its SqlBridge across activation and warm turns.
 * Handles are numbered once per interpreter and may execute only while their
 * own bridge is active.
 *
 * @internal
 */
final class NativePdo
{
    private static ?SqlBridge $bridge = null;
    private static bool $active = false;
    private static int $lastHandle = 0;
    private static int $firstHandle = 1;

    public static function enter(SqlBridge $bridge): void
    {
        if (self::$bridge !== $bridge) {
            self::$bridge = $bridge;
            self::$firstHandle = self::$lastHandle + 1;
        }
        self::$active = true;
    }

    public static function leave(): void
    {
        self::$active = false;
        if (extension_loaded('pdo_atoms')) {
            native_pdo_close_cursors();
        }
    }

    public static function call(string $op, int $handle, ?string $sql, array $bindings): mixed
    {
        $bridge = self::$active ? self::$bridge : null;
        $current = $bridge !== null && self::$firstHandle <= $handle && $handle <= self::$lastHandle;

        // PDO also asks about ownership during destruction, after the last turn.
        if ($op === 'owns') {
            return $current && $bridge->ownedBy($handle);
        }
        if ($bridge === null) {
            throw new \PDOException('The atoms: connection requires an active Atom.');
        }
        if ($op === 'open') {
            return ++self::$lastHandle;
        }
        if (!$current) {
            throw new \PDOException('This atoms: connection belongs to a different Atom instance.');
        }

        switch ($op) {
            case 'check':
                return true;
            case 'begin':
                $bridge->begin($handle);
                return true;
            case 'commit':
                $bridge->commit($handle);
                return true;
            case 'rollback':
                $bridge->rollback($handle);
                return true;
            case 'lastId':
                return $bridge->lastInsertId();
            case 'exec':
                return $bridge->exec($sql, [], SqlBridge::MODE_RUN)['rows_written'];
            case 'execute':
                [$query, $params] = NamedParams::rewrite($sql, $bindings);
                $result = $bridge->exec($query, $params);
                FetchMode::refuseDuplicateColumns('native PDO', $result['columns']);
                return $result;
            default:
                throw new \LogicException('Unknown native PDO operation: ' . $op);
        }
    }
}

/** @internal Called from the PDO driver method tables. */
function native_pdo(string $op, int $handle, ?string $sql = null, array $bindings = []): mixed
{
    return NativePdo::call($op, $handle, $sql, $bindings);
}
