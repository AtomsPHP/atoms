<?php

namespace App\Pdo;

/**
 * Weak-mode type boundaries.
 *
 * Every other case in this differential matrix runs from {@see Cases}, which
 * declares `declare(strict_types=1)` — so a call boundary that behaves
 * differently under PHP's ordinary WEAK typing is never exercised anywhere
 * else in this matrix. `AtomsPDO::quote()`'s own `string $string` parameter
 * type exists specifically so a call real PDO
 * refuses at the argument boundary under `strict_types=1` is refused on our
 * side too — but that opens a DIFFERENT gap: under WEAK typing,
 * `quote(null)` on a real (internal/C) `\PDO::quote()` still coerces `null`
 * to the empty string (PHP's legacy leniency for internal functions passing
 * `null` to a non-nullable scalar parameter — deprecated as of 8.1, but not
 * an error), while our `quote()` is a USERLAND method, and userland
 * functions never got that leniency: PHP raises a `\TypeError` for `null`
 * against a non-nullable scalar-typed parameter in a userland function
 * regardless of the caller's `strict_types` setting. This file is
 * DELIBERATELY the one caller in the whole matrix WITHOUT
 * `declare(strict_types=1)`, so this gap — and only this gap — is exercised
 * here, isolated from every other case's strict-mode call boundary.
 *
 * Same case shape, same {@see Differential} harness, same pin file as
 * {@see Cases} — {@see Cases::all()} merges this class's cases in, so the
 * group appears in the matrix exactly like any other.
 */
final class CasesWeak
{
    private function __construct()
    {
    }

    /**
     * @return list<array{
     *     id: string, group: string, member: string, title: string,
     *     sqlstate_strict: bool, informational: bool, run: \Closure
     * }>
     */
    private static function c(
        $id,
        $group,
        $member,
        $title,
        \Closure $run,
        $sqlstateStrict = false,
        $informational = false
    ) {
        return [
            'id' => $id,
            'group' => $group,
            'member' => $member,
            'title' => $title,
            'sqlstate_strict' => $sqlstateStrict,
            'informational' => $informational,
            'run' => $run,
        ];
    }

    /**
     * @return list<array{
     *     id: string, group: string, member: string, title: string,
     *     sqlstate_strict: bool, informational: bool, run: \Closure
     * }>
     */
    public static function all()
    {
        $g = 'Weak-mode type boundaries';

        return [
            self::c(
                'pdo.quote.weak_null',
                $g,
                'PDO::quote()',
                'quote(null) under WEAK typing (this file has no declare(strict_types=1))',
                static function (\PDO $p) {
                    return $p->quote(null);
                }
            ),
            self::c(
                'pdo.quote.weak_bool_true',
                $g,
                'PDO::quote()',
                'quote(true) under weak typing — bool coerces to a string on both sides',
                static function (\PDO $p) {
                    return $p->quote(true);
                }
            ),
            self::c(
                'pdo.quote.weak_int',
                $g,
                'PDO::quote()',
                'quote(42) under weak typing — int coerces to a string on both sides',
                static function (\PDO $p) {
                    return $p->quote(42);
                }
            ),
            self::c(
                'bind.value.weak_int_string',
                $g,
                'PDOStatement::bindValue() / execute()',
                'bind and read back an ordinary value from a case file with no declare(strict_types=1)',
                static function (\PDO $p) {
                    $stmt = $p->prepare('SELECT ? AS v, typeof(?) AS t');
                    $stmt->bindValue(1, 42, \PDO::PARAM_STR);
                    $stmt->bindValue(2, 42, \PDO::PARAM_STR);
                    $stmt->execute();

                    return $stmt->fetch(\PDO::FETCH_ASSOC);
                }
            ),
        ];
    }
}
