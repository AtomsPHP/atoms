<?php

/**
 * The subset of PDO fetch modes the MVP shim serves, and the row reshaping for
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
