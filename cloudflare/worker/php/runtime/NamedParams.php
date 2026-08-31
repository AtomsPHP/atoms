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
     * @return array{0: string, 1: list<mixed>} rewritten SQL and positional bindings
     * @throws \PDOException HY093 for a missing or unused named binding
     */
    public static function rewrite($sql, array $bindings)
    {
        $used = [];

        if (self::isPositional($bindings)) {
            if ($bindings === [] && strpos($sql, ':') !== false) {
                // No bindings at all, but the statement may still carry a
                // `:name`. SQLite binds unsupplied parameters to NULL without
                // complaining, so this has to be caught here or it becomes a
                // silently wrong answer. The strpos() guard keeps the common
                // no-parameter statement off the scanner.
                return self::scan($sql, [], $used);
            }

            // PDO's positional keys are 1-based and may arrive out of order
            // (bindValue(2, ...) before bindValue(1, ...)), so order by key.
            ksort($bindings, SORT_NUMERIC);

            return [$sql, array_values($bindings)];
        }

        $lookup = [];
        foreach ($bindings as $key => $value) {
            $lookup[ltrim((string) $key, ':')] = $value;
        }

        list($rewritten, $positional) = self::scan($sql, $lookup, $used);

        $unused = array_diff(array_keys($lookup), array_keys($used));
        if ($unused !== []) {
            throw new \PDOException(sprintf(
                'SQLSTATE[HY093] Invalid parameter number: binding(s) :%s not present in the statement.',
                implode(', :', $unused)
            ));
        }

        return [$rewritten, $positional];
    }

    /**
     * Walk the statement, replacing `:name` outside literals and comments with
     * `?` and collecting the matching values in order.
     *
     * @param string $sql
     * @param array<string, mixed> $lookup binding values by bare name
     * @param array<string, bool> $used out-parameter: names actually referenced
     * @return array{0: string, 1: list<mixed>}
     * @throws \PDOException HY093 when a placeholder has no binding
     */
    private static function scan($sql, array $lookup, &$used)
    {
        $used = [];
        $positional = [];
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

                    if (!array_key_exists($name, $lookup)) {
                        throw new \PDOException(sprintf(
                            'SQLSTATE[HY093] Invalid parameter number: no binding supplied for :%s.',
                            $name
                        ));
                    }

                    $positional[] = $lookup[$name];
                    $used[$name] = true;
                    $out .= '?';
                    $i += strlen($m[0]);
                    continue;
                }
            }

            $out .= $char;
            $i++;
        }

        return [$out, $positional];
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
