<?php

/**
 * The subset of PDO fetch modes this shim serves, and the row reshaping for
 * each. Anything outside the subset raises {@see AtomsNotSupported} rather than
 * quietly degrading to associative rows.
 *
 * The bridge always receives rows as `{column: value}` maps from
 * `ctx.storage.sql`, so every supported mode is a pure reshaping of that.
 *
 * No declare(strict_types=1) — see the note in host.php.
 */

namespace Atoms\Cf;

final class FetchMode
{
    private function __construct()
    {
    }

    /**
     * Audit F23: the single table behind THE one duplicate-column guard.
     * Maps each mode that reads a result row by its ORIGINAL positional
     * arity — and so cannot be answered faithfully once the wire's
     * last-wins `{column: value}` collapse has folded duplicate column
     * names — to its label for {@see refuseDuplicateColumns}'s
     * message. ASSOC/OBJ are deliberately absent: they collapse identically
     * on both sides (a JS object and a PHP associative array both last-wins),
     * so they stay a genuine match, not a refusal.
     */
    private const NEEDS_TRUE_ARITY = [
        \PDO::FETCH_NUM => 'PDO::FETCH_NUM',
        \PDO::FETCH_BOTH => 'PDO::FETCH_BOTH',
        \PDO::FETCH_COLUMN => 'PDO::FETCH_COLUMN',
    ];

    /**
     * Audit F23: the ONE refusal message template. Every duplicate-column
     * refusal in the SQL bridge renders through this string, so the wording
     * can never drift between call sites again. The literal
     * `SQLSTATE[HY000]: ` prefix is load-bearing — Normalize::sqlstate()
     * parses it off the message whenever errorInfo[0] is absent.
     */
    private const DUPLICATE_COLUMNS_MESSAGE =
        'SQLSTATE[HY000]: General error: %s cannot see this result set\'s true column positions — the '
        . 'Atoms bridge\'s wire has already collapsed duplicate column names (last value wins) before '
        . 'this fetch mode could recover them. Use FETCH_ASSOC/FETCH_OBJ (which collapse identically '
        . 'to real pdo_sqlite), or alias the duplicate columns distinctly instead.';

    /**
     * @return list<int> the modes this shim implements
     */
    public static function supported()
    {
        return [
            \PDO::FETCH_ASSOC,
            \PDO::FETCH_NUM,
            \PDO::FETCH_BOTH,
            \PDO::FETCH_OBJ,
            \PDO::FETCH_COLUMN,
            \PDO::FETCH_KEY_PAIR,
        ];
    }

    /**
     * @param int $mode
     * @param string $context what asked, for the exception message
     * @return int the normalized mode (FETCH_DEFAULT becomes FETCH_ASSOC)
     * @throws AtomsNotSupported
     */
    public static function assertSupported($mode, $context)
    {
        $mode = (int) $mode;

        if ($mode === \PDO::FETCH_DEFAULT) {
            return \PDO::FETCH_ASSOC;
        }

        if (in_array($mode, self::supported(), true)) {
            return $mode;
        }

        throw new AtomsNotSupported(
            sprintf('%s %d', $context, $mode),
            'Supported modes are FETCH_ASSOC, FETCH_NUM, FETCH_BOTH, FETCH_OBJ, FETCH_COLUMN and FETCH_KEY_PAIR.'
        );
    }

    /**
     * THE duplicate-column guard (audit F23 — one function, one message
     * template, used at every site in the SQL bridge that consumes a result
     * set positionally). Refuses (rather than silently answering with the
     * wrong arity) whenever `$columns` — the SOURCE-ORDER column names with
     * duplicates preserved, from `cursor.columnNames` via SqlBridge (Branch
     * A) — reports a duplicate, for:
     *
     * - a mode listed in {@see NEEDS_TRUE_ARITY} (FETCH_NUM/FETCH_BOTH/
     *   FETCH_COLUMN), where collapse changes what the caller gets; and
     * - any labeled entry point passed a string (`$modeOrLabel`) whose mode
     *   is positional no matter how it was reached: FETCH_FUNC's spread,
     *   FETCH_KEY_PAIR's pair, FETCH_GROUP/FETCH_UNIQUE's first-column key,
     *   FETCH_BOUND's by-index AND by-name targets, FETCH_CLASSTYPE's
     *   classname column, and FETCH_NAMED's grouping.
     *
     * A mode absent from NEEDS_TRUE_ARITY passed as an INT returns silently:
     * ASSOC/OBJ collapse identically on both sides, so they stay a genuine
     * match, not a refusal.
     *
     * @param int|string $modeOrLabel a PDO::FETCH_* base mode, or an explicit
     *        label for the entry point being refused
     * @param list<string> $columns source-order column names, duplicates preserved
     * @throws \PDOException with the ONE {@see DUPLICATE_COLUMNS_MESSAGE} template
     */
    public static function refuseDuplicateColumns($modeOrLabel, array $columns)
    {
        $what = is_string($modeOrLabel)
            ? $modeOrLabel
            : (self::NEEDS_TRUE_ARITY[(int) $modeOrLabel] ?? null);

        if ($what === null || count($columns) === count(array_unique($columns))) {
            return;
        }

        throw new \PDOException(sprintf(self::DUPLICATE_COLUMNS_MESSAGE, $what));
    }

    /**
     * Reshape one `{column: value}` row into the requested mode.
     *
     * @param array<string, mixed> $row
     * @param int $mode already normalized by assertSupported()
     * @return mixed
     * @throws AtomsNotSupported for modes that are meaningless for a single row
     */
    public static function shape(array $row, $mode)
    {
        switch ($mode) {
            case \PDO::FETCH_ASSOC:
                return $row;

            case \PDO::FETCH_NUM:
                return array_values($row);

            case \PDO::FETCH_BOTH:
                $out = [];
                $index = 0;
                foreach ($row as $column => $value) {
                    $out[$column] = $value;
                    $out[$index] = $value;
                    $index++;
                }

                return $out;

            case \PDO::FETCH_OBJ:
                return (object) $row;

            case \PDO::FETCH_COLUMN:
                $values = array_values($row);

                return array_key_exists(0, $values) ? $values[0] : null;
        }

        throw new AtomsNotSupported(
            sprintf('fetch mode %d for a single row', $mode),
            'PDO::FETCH_KEY_PAIR is only meaningful for fetchAll().'
        );
    }
}
