<?php

declare(strict_types=1);

namespace App\Pdo;

use App\Pdo\Fixtures\LateRow;
use App\Pdo\Fixtures\PrivateRow;
use App\Pdo\Fixtures\Promoted;
use App\Pdo\Fixtures\Row;

/**
 * The differential harness's case matrix (M1 design §2.8).
 *
 * Every case is ONE closure taking a single `\PDO $pdo` — the identical
 * closure runs against `Atoms\Cf\AtomsPDO` (via `db()->pdo()`) and a native
 * in-guest `new \PDO('sqlite::memory:')` (the {@see Comparator}). There is
 * no "ours" branch and no "theirs" branch anywhere below: that is the
 * single most important structural property of this file.
 *
 * Every SELECT carries a deterministic ORDER BY (or is scoped to exactly
 * one seeded row) — row order is never normalized away, so an ordering bug
 * is never hidden by a shape-only comparison. Seed data: {@see Schema}
 * seeds `probe_rows` with three rows, k='a'/'b'/'c' (== id order):
 *   a: i=1  r=1.5  s='hello' n=NULL
 *   b: i=2  r=2.0  s='world' n='nb'
 *   c: i=3  r=3.25 s=''      n='nc'
 *
 * A case's outcome (a returned value, or an exception that propagates out
 * of the closure) is what {@see Differential} captures and classifies —
 * neither is privileged: whichever the same closure produces on each side
 * is what gets compared. A case that wants to compare something OTHER than
 * "did it throw" (an exception's ->getCode(), a captured output buffer, a
 * connection's errorCode() after a caught statement failure) catches
 * internally and returns the captured thing as an ordinary value; that
 * conversion is a property of the CASE, never of the harness.
 */
final class Cases
{
    private function __construct()
    {
    }

    /**
     * @return list<string> group names, in declaration order — the single
     *     source of truth Probe::differentialGroups() returns, so the group
     *     list can never drift from what all() actually contains.
     */
    public static function groups(): array
    {
        $seen = [];
        $out = [];

        foreach (self::all() as $case) {
            if (!isset($seen[$case['group']])) {
                $seen[$case['group']] = true;
                $out[] = $case['group'];
            }
        }

        return $out;
    }

    /**
     * @return list<array{
     *     id: string, group: string, member: string, title: string,
     *     sqlstate_strict: bool, informational: bool, run: \Closure
     * }>
     */
    public static function all(): array
    {
        return array_merge(
            self::connectionStatements(),
            self::connectionAttributes(),
            self::connectionQuoting(),
            self::transactions(),
            self::idsAndCounts(),
            self::binding(),
            self::fetchModes(),
            self::valuesAndRoundTrips(),
            self::errors(),
            self::duplicateColumns(),
            self::statementMisc()
        );
    }

    /**
     * @param bool $sqlstateStrict when both sides throw, require matching
     *     SQLSTATEs too (design §2.5) — only for engine-produced errors
     *     (constraint violation, syntax error, FETCH_KEY_PAIR arity).
     * @param bool $informational never compared; both sides' outcomes are
     *     recorded but the case is always classified 'informational'
     *     (design §2.5 — PDOStatement::rowCount() after a SELECT).
     */
    private static function c(
        string $id,
        string $group,
        string $member,
        string $title,
        \Closure $run,
        bool $sqlstateStrict = false,
        bool $informational = false
    ): array {
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

    // ---------------------------------------------------------- statements

    private static function connectionStatements(): array
    {
        $g = 'Connection — statements';

        return [
            self::c($g . '#1', $g, 'PDO::exec()', 'x', static fn (\PDO $p) => 1), // placeholder removed below
        ];
    }
}
