<?php

/**
 * Named→positional parameter rewriting, done in PHP.
 *
 * The `sql.exec` wire op carries positional bindings only (runtime-spec.md
 * §Sync ops), so `:name` placeholders are resolved on this side of the door —
 * shared by {@see BridgeDatabase} (Atoms\Database) and {@see AtomsStatement}
 * (the \PDO surface) so both accept exactly the same SQL.
 *
 * The scanner skips string literals, quoted and bracketed identifiers, and both
 * comment forms, so a ':' inside them is text rather than a placeholder.
 *
 * No declare(strict_types=1) — see the note in host.php.
 */

namespace Atoms\Cf;

final class NamedParams
{
    private function __construct()
    {
    }

    /**
     * @param string $sql
     * @param array<int|string, mixed> $bindings
     * @param int $firstPosition Numeric keys start at one in bindValue() and bindParam().
     * @param array<string, int> $names Named parameter slots for PDO's debug output.
     * @return array{0: string, 1: list<mixed>} rewritten SQL and positional bindings
     * @throws \PDOException HY093 for a missing or unused binding
     */
    public static function rewrite($sql, array $bindings, int $firstPosition = 0, array &$names = [])
    {
        $names = [];
        if (self::isPositional($bindings) && ($bindings !== [] || strpos($sql, ':') === false)) {
            ksort($bindings, SORT_NUMERIC);

            return [$sql, array_values($bindings)];
        }

        list($rewritten, $slots, $names) = self::scan($sql);
        $last = $slots === [] ? -1 : max($slots);
        $values = [];
        foreach ($bindings as $key => $value) {
            $slot = is_int($key) ? $key - $firstPosition : ($names[ltrim($key, ':')] ?? null);
            if ($slot === null || $slot < 0 || $slot > $last) {
                throw new \PDOException(sprintf(
                    'SQLSTATE[HY093] Invalid parameter number: binding %s not present in the statement.',
                    $key
                ));
            }
            $values[$slot] = $value;
        }

        $positional = [];
        foreach ($slots as $slot) {
            if (!array_key_exists($slot, $values)) {
                $name = array_search($slot, $names, true);
                throw new \PDOException(sprintf(
                    'SQLSTATE[HY093] Invalid parameter number: no binding supplied for %s.',
                    $name === false ? 'position ' . ($slot + 1) : ':' . $name
                ));
            }
            $positional[] = $values[$slot];
        }

        return [$rewritten, $positional];
    }

    /** @return array{string, list<int>, array<string, int>} SQL, slots in query order, and named slots */
    private static function scan($sql)
    {
        $slots = [];
        $names = [];
        $next = 0;
        $out = '';
        $length = strlen($sql);
        $i = 0;

        while ($i < $length) {
            $char = $sql[$i];

            if ($char === "'" || $char === '"' || $char === '`') {
                $end = self::skipQuoted($sql, $i, $char);
                $out .= substr($sql, $i, $end - $i);
                $i = $end;
                continue;
            }

            if ($char === '[') {
                $close = strpos($sql, ']', $i + 1);
                $end = $close === false ? $length : $close + 1;
                $out .= substr($sql, $i, $end - $i);
                $i = $end;
                continue;
            }

            if ($char === '-' && $i + 1 < $length && $sql[$i + 1] === '-') {
                $close = strpos($sql, "\n", $i);
                $end = $close === false ? $length : $close + 1;
                $out .= substr($sql, $i, $end - $i);
                $i = $end;
                continue;
            }

            if ($char === '/' && $i + 1 < $length && $sql[$i + 1] === '*') {
                $close = strpos($sql, '*/', $i + 2);
                $end = $close === false ? $length : $close + 2;
                $out .= substr($sql, $i, $end - $i);
                $i = $end;
                continue;
            }

            if ($char === ':') {
                // '::' is a cast operator, never a placeholder.
                if ($i + 1 < $length && $sql[$i + 1] === ':') {
                    $out .= '::';
                    $i += 2;
                    continue;
                }

                if (preg_match('/^:([A-Za-z_][A-Za-z0-9_]*)/', substr($sql, $i), $m) === 1) {
                    $name = $m[1];

                    $names[$name] ??= $next++;
                    $slots[] = $names[$name];
                    $out .= '?';
                    $i += strlen($m[0]);
                    continue;
                }
            }

            if ($char === '?') {
                if (isset($sql[$i + 1]) && ctype_digit($sql[$i + 1])) {
                    throw new \PDOException('SQLSTATE[HY093] Use plain ? or :name placeholders.');
                }
                $slots[] = $next++;
            }
            $out .= $char;
            $i++;
        }

        return [$out, $slots, $names];
    }

    /**
     * @param array<int|string, mixed> $bindings
     * @return bool
     */
    private static function isPositional(array $bindings)
    {
        foreach ($bindings as $key => $ignored) {
            if (!is_int($key)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Index just past the closing quote of the literal starting at $start.
     * SQL doubles the quote character to escape it ('' / "" / ``).
     *
     * @param string $sql
     * @param int $start
     * @param string $quote
     * @return int
     */
    private static function skipQuoted($sql, $start, $quote)
    {
        $length = strlen($sql);
        $i = $start + 1;

        while ($i < $length) {
            if ($sql[$i] !== $quote) {
                $i++;
                continue;
            }

            if ($i + 1 < $length && $sql[$i + 1] === $quote) {
                $i += 2;
                continue;
            }

            return $i + 1;
        }

        return $length;
    }
}
