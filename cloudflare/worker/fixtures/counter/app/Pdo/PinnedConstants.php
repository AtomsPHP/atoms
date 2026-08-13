<?php

declare(strict_types=1);

namespace App\Pdo;

/**
 * The pinned name=>value maps for every \PDO constant namespace the
 * reflection tripwire audits (M1 design §1.2, rule R5).
 *
 * MEASURED on local PHP 8.4.19 / SQLite 3.45.1 (`php -r`, reflecting
 * \PDO::class, driver-namespaced constants excluded — see
 * SurfaceAudit::DRIVER_PREFIX). The guest is php-wasm PHP 8.3.32: these
 * values are the STARTING pin, and the first in-guest run of check 26 is
 * what confirms them. A mismatch is a red build the implementer resolves by
 * recording the measured 8.3 value here with a comment — that is rule R5
 * working as designed (m1-design.md §0.1, §1.4), not a bug in the pin.
 *
 * `ERR_NONE` is a lone constant, not a family — it is the only public
 * `ERR_*`-prefixed constant \PDO declares (measured); it is kept in its own
 * category for that reason.
 */
final class PinnedConstants
{
    private function __construct()
    {
    }

    /**
     * @return array<string, int>
     */
    public static function fetch(): array
    {
        return [
            'FETCH_DEFAULT' => 0,
            'FETCH_LAZY' => 1,
            'FETCH_ASSOC' => 2,
            'FETCH_NUM' => 3,
            'FETCH_BOTH' => 4,
            'FETCH_OBJ' => 5,
            'FETCH_BOUND' => 6,
            'FETCH_COLUMN' => 7,
            'FETCH_CLASS' => 8,
            'FETCH_INTO' => 9,
            'FETCH_FUNC' => 10,
            'FETCH_NAMED' => 11,
            'FETCH_KEY_PAIR' => 12,
            'FETCH_ORI_NEXT' => 0,
            'FETCH_ORI_PRIOR' => 1,
            'FETCH_ORI_FIRST' => 2,
            'FETCH_ORI_LAST' => 3,
            'FETCH_ORI_ABS' => 4,
            'FETCH_ORI_REL' => 5,
            'FETCH_GROUP' => 65536,
            'FETCH_UNIQUE' => 196608,
            'FETCH_CLASSTYPE' => 262144,
            'FETCH_SERIALIZE' => 524288,
            'FETCH_PROPS_LATE' => 1048576,
        ];
    }

    /**
     * @return array<string, int>
     */
    public static function attr(): array
    {
        return [
            'ATTR_AUTOCOMMIT' => 0,
            'ATTR_PREFETCH' => 1,
            'ATTR_TIMEOUT' => 2,
            'ATTR_ERRMODE' => 3,
            'ATTR_SERVER_VERSION' => 4,
            'ATTR_CLIENT_VERSION' => 5,
            'ATTR_SERVER_INFO' => 6,
            'ATTR_CONNECTION_STATUS' => 7,
            'ATTR_CASE' => 8,
            'ATTR_CURSOR_NAME' => 9,
            'ATTR_CURSOR' => 10,
            'ATTR_ORACLE_NULLS' => 11,
            'ATTR_PERSISTENT' => 12,
            'ATTR_STATEMENT_CLASS' => 13,
            'ATTR_FETCH_TABLE_NAMES' => 14,
            'ATTR_FETCH_CATALOG_NAMES' => 15,
            'ATTR_DRIVER_NAME' => 16,
            'ATTR_STRINGIFY_FETCHES' => 17,
            'ATTR_MAX_COLUMN_LEN' => 18,
            'ATTR_DEFAULT_FETCH_MODE' => 19,
            'ATTR_EMULATE_PREPARES' => 20,
            'ATTR_DEFAULT_STR_PARAM' => 21,
        ];
    }

    /**
     * Includes the 7 `PARAM_EVT_*` persistence-event constants (measured:
     * 16 `PARAM_*` names total on 8.4, not just the 9 value-type ones).
     *
     * @return array<string, int>
     */
    public static function param(): array
    {
        return [
            'PARAM_NULL' => 0,
            'PARAM_INT' => 1,
            'PARAM_STR' => 2,
            'PARAM_LOB' => 3,
            'PARAM_STMT' => 4,
            'PARAM_BOOL' => 5,
            'PARAM_STR_CHAR' => 536870912,
            'PARAM_STR_NATL' => 1073741824,
            'PARAM_INPUT_OUTPUT' => 2147483648,
            'PARAM_EVT_ALLOC' => 0,
            'PARAM_EVT_FREE' => 1,
            'PARAM_EVT_EXEC_PRE' => 2,
            'PARAM_EVT_EXEC_POST' => 3,
            'PARAM_EVT_FETCH_PRE' => 4,
            'PARAM_EVT_FETCH_POST' => 5,
            'PARAM_EVT_NORMALIZE' => 6,
        ];
    }

    /**
     * @return array<string, int>
     */
    public static function errmode(): array
    {
        return [
            'ERRMODE_SILENT' => 0,
            'ERRMODE_WARNING' => 1,
            'ERRMODE_EXCEPTION' => 2,
        ];
    }

    /**
     * @return array<string, int>
     */
    public static function caseMode(): array
    {
        return [
            'CASE_NATURAL' => 0,
            'CASE_UPPER' => 1,
            'CASE_LOWER' => 2,
        ];
    }

    /**
     * @return array<string, int>
     */
    public static function nullHandling(): array
    {
        return [
            'NULL_NATURAL' => 0,
            'NULL_EMPTY_STRING' => 1,
            'NULL_TO_STRING' => 2,
        ];
    }

    /**
     * @return array<string, int>
     */
    public static function cursor(): array
    {
        return [
            'CURSOR_FWDONLY' => 0,
            'CURSOR_SCROLL' => 1,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function errNone(): array
    {
        return [
            'ERR_NONE' => '00000',
        ];
    }

    /**
     * M1 review F-9 (MAJOR, fixed): the set of `PDO::FETCH_*` names that
     * `SurfaceAudit::auditFetchBehaviour()` must observe as ANSWERED when
     * each is driven through the REAL `Atoms\Cf\AtomsStatement` dispatcher
     * (not the small internal `FetchMode` helper — see that method's
     * docblock for why the distinction matters). Every other name in
     * {@see fetch()} is expected to raise `AtomsNotSupported`. This is a
     * closed pin, not a computed derivation: deleting a `hydrateOneRow()`
     * arm removes a name from the OBSERVED answered set without touching
     * this one, so the two disagree and R6 goes red — and a mode that
     * starts silently answering (should have stayed refused) does the
     * same in the other direction.
     *
     * @return list<string>
     */
    public static function expectedAnsweredFetchModes(): array
    {
        return [
            'FETCH_DEFAULT',
            'FETCH_ASSOC',
            'FETCH_NUM',
            'FETCH_BOTH',
            'FETCH_OBJ',
            'FETCH_BOUND',
            'FETCH_COLUMN',
            'FETCH_CLASS',
            'FETCH_INTO',
            'FETCH_FUNC',
            'FETCH_NAMED',
            'FETCH_KEY_PAIR',
            'FETCH_ORI_NEXT',
            'FETCH_GROUP',
            'FETCH_UNIQUE',
            'FETCH_CLASSTYPE',
            'FETCH_PROPS_LATE',
        ];
    }

    /**
     * Every pinned constant, across every category, flattened into one
     * name=>value map. Category boundaries never overlap by construction
     * (each is a distinct prefix, `ERR_` vs `ERRMODE_` verified distinct by
     * SurfaceAudit's prefix matching), so a plain array union is safe.
     *
     * @return array<string, int|string>
     */
    public static function all(): array
    {
        return self::fetch()
            + self::attr()
            + self::param()
            + self::errmode()
            + self::caseMode()
            + self::nullHandling()
            + self::cursor()
            + self::errNone();
    }
}
