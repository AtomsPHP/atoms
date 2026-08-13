<?php

declare(strict_types=1);

namespace App\Pdo;

/**
 * Runs one group of {@see Cases}' matrix against both sides and classifies
 * every case (M1 design §2.4, §2.10). The comparison happens IN-GUEST —
 * only classifications and short renderings cross the turn boundary, so
 * int64 and float fidelity are never at the mercy of the wire under test.
 */
final class Differential
{
    private const PINNABLE = ['refused_by_us', 'refused_by_both', 'refused_by_comparator', 'deviation'];

    private function __construct()
    {
    }

    /**
     * @return array{
     *     group: string, php: string,
     *     comparator: array{ok: bool, gates: array<string, bool>},
     *     summary: array<string, int>,
     *     cases: list<array{id: string, group: string, member: string, title: string, class: string, ours: string, theirs: string, detail: string}>
     * }
     */
    public static function run(string $group, \PDO $ours, \PDO $comparator): array
    {
        $comparatorSanity = Comparator::sanity($comparator);

        $summary = [
            'total' => 0,
            'match' => 0,
            'refused_by_us' => 0,
            'refused_by_both' => 0,
            'refused_by_comparator' => 0,
            'deviation' => 0,
            'informational' => 0,
            'error' => 0,
        ];

        $cases = [];
        foreach (Cases::all() as $case) {
            if ($case['group'] !== $group) {
                continue;
            }

            $result = self::runCase($case, $ours, $comparator);
            $cases[] = $result;
            $summary['total']++;
            $summary[$result['class']]++;
        }

        return [
            'group' => $group,
            'php' => PHP_VERSION,
            'comparator' => $comparatorSanity,
            'summary' => $summary,
            'cases' => $cases,
        ];
    }

    /**
     * @param array{id: string, group: string, member: string, title: string, sqlstate_strict: bool, informational: bool, run: \Closure} $case
     * @return array{id: string, group: string, member: string, title: string, class: string, ours: string, theirs: string, detail: string}
     */
    private static function runCase(array $case, \PDO $ours, \PDO $comparator): array
    {
        // Defensive, case-shape-independent identity for the error path
        // below — built BEFORE anything that could throw, so a malformed
        // record still produces identifiable evidence instead of losing its
        // id along with everything else.
        $id = isset($case['id']) && is_string($case['id']) ? $case['id'] : '(malformed case: missing id)';
        $group = isset($case['group']) && is_string($case['group']) ? $case['group'] : '(malformed case: missing group)';
        $member = isset($case['member']) && is_string($case['member']) ? $case['member'] : '(malformed case: missing member)';
        $title = isset($case['title']) && is_string($case['title']) ? $case['title'] : '(malformed case: missing title)';

        try {
            // M1 review F-3 (MAJOR, fixed): classify()/renderOutcome() now
            // run INSIDE this try, alongside explicit shape validation, so
            // (a) a Normalize refusal (a value the normalizer can't render)
            // lands as 'error' instead of propagating out of runCase()
            // uncaught and taking the whole group's turn down with it, and
            // (b) a malformed case record (a missing key, a non-Closure
            // "run") is caught here rather than fataling on array access or
            // a call-time TypeError from capture()'s \Closure type-hint.
            // capture() itself is UNCHANGED: a PDO call legitimately
            // throwing TypeError/ValueError/etc is still captured there as
            // an ordinary outcome, not treated as harness breakage.
            foreach (['id', 'group', 'member', 'title', 'run', 'sqlstate_strict', 'informational'] as $key) {
                if (!array_key_exists($key, $case)) {
                    throw new \LogicException(sprintf('case record is malformed: missing key "%s"', $key));
                }
            }
            if (!($case['run'] instanceof \Closure)) {
                throw new \LogicException('case record is malformed: "run" is not a Closure');
            }

            $oursOutcome = self::capture($case['run'], $ours);
            $theirsOutcome = self::capture($case['run'], $comparator);

            if ($case['informational']) {
                // M1 review F-2 (BLOCKER): 'informational' is a closed set
                // of exactly one case id (design §2.5's
                // rowCount()-after-SELECT exception) — never an open escape
                // from the pin rules (which skip 'informational' outright)
                // that a new case could opt into merely by setting this
                // flag. conformance.mjs check 28 asserts the observed set
                // matches this same singleton from the runner side; this is
                // the harness-side half of that same guarantee.
                if ($case['id'] !== 'count.rowcount.select') {
                    throw new \LogicException(sprintf(
                        'case "%s" is marked informational, but only "count.rowcount.select" may be (M1 review F-2)',
                        $case['id']
                    ));
                }

                return self::shape(
                    $case,
                    'informational',
                    self::renderOutcome($oursOutcome),
                    self::renderOutcome($theirsOutcome),
                    'not compared by design — PDOStatement::rowCount() after a SELECT is undefined by PDO\'s own contract'
                );
            }

            [$class, $detail] = self::classify($oursOutcome, $theirsOutcome, $case['sqlstate_strict']);

            return self::shape($case, $class, self::renderOutcome($oursOutcome), self::renderOutcome($theirsOutcome), $detail);
        } catch (\Throwable $e) {
            // The HARNESS broke (a malformed case record, an informational
            // id outside the closed set, or a value the normalizer refuses
            // to render) — never the case-under-test's own outcome, and
            // never pinnable (design §2.4).
            return [
                'id' => $id,
                'group' => $group,
                'member' => $member,
                'title' => $title,
                'class' => 'error',
                'ours' => '(harness error)',
                'theirs' => '(harness error)',
                'detail' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param array{id: string, group: string, member: string, title: string} $case
     * @return array{id: string, group: string, member: string, title: string, class: string, ours: string, theirs: string, detail: string}
     */
    private static function shape(array $case, string $class, string $ours, string $theirs, string $detail): array
    {
        return [
            'id' => $case['id'],
            'group' => $case['group'],
            'member' => $case['member'],
            'title' => $case['title'],
            'class' => $class,
            'ours' => $ours,
            'theirs' => $theirs,
            'detail' => $detail,
        ];
    }

    /**
     * @return array{value?: mixed, throwable?: \Throwable}
     */
    private static function capture(\Closure $run, \PDO $pdo): array
    {
        try {
            return ['value' => $run($pdo)];
        } catch (\Throwable $e) {
            return ['throwable' => $e];
        }
    }

    /**
     * @param array{value?: mixed, throwable?: \Throwable} $outcome
     */
    private static function renderOutcome(array $outcome): string
    {
        if (array_key_exists('throwable', $outcome)) {
            return Normalize::render(Normalize::throwable($outcome['throwable']));
        }

        return Normalize::render(Normalize::value($outcome['value']));
    }

    /**
     * @param array{value?: mixed, throwable?: \Throwable} $ours
     * @param array{value?: mixed, throwable?: \Throwable} $theirs
     * @return array{0: string, 1: string}
     */
    private static function classify(array $ours, array $theirs, bool $sqlstateStrict): array
    {
        $oursThrew = array_key_exists('throwable', $ours);
        $theirsThrew = array_key_exists('throwable', $theirs);

        if (!$oursThrew && !$theirsThrew) {
            $oursTree = Normalize::value($ours['value']);
            $theirsTree = Normalize::value($theirs['value']);

            return $oursTree === $theirsTree
                ? ['match', '']
                : ['deviation', 'both sides answered a value, but they differ'];
        }

        if (!$oursThrew && $theirsThrew) {
            return [
                'refused_by_comparator',
                sprintf('ours answered a value; the comparator (real pdo_sqlite) refused with %s', Normalize::family($theirs['throwable'])),
            ];
        }

        if ($oursThrew && !$theirsThrew) {
            return [
                'refused_by_us',
                sprintf('ours refused with %s; the comparator (real pdo_sqlite) answered a value', Normalize::family($ours['throwable'])),
            ];
        }

        // Both threw.
        $oursFamily = Normalize::family($ours['throwable']);
        $theirsFamily = Normalize::family($theirs['throwable']);

        if ($oursFamily !== $theirsFamily) {
            return [
                'deviation',
                sprintf('both threw, but different exception families: ours=%s theirs=%s', $oursFamily, $theirsFamily),
            ];
        }

        if ($sqlstateStrict) {
            $oursState = Normalize::sqlstate($ours['throwable']);
            $theirsState = Normalize::sqlstate($theirs['throwable']);

            if ($oursState !== $theirsState) {
                return [
                    'deviation',
                    sprintf(
                        'both threw %s, but SQLSTATEs differ: ours=%s theirs=%s',
                        $oursFamily,
                        $oursState ?? 'null',
                        $theirsState ?? 'null'
                    ),
                ];
            }

            return ['refused_by_both', sprintf('both threw %s with matching SQLSTATE %s', $oursFamily, $oursState ?? 'null')];
        }

        return ['refused_by_both', sprintf('both threw %s', $oursFamily)];
    }

    /**
     * @return list<string>
     */
    public static function pinnableClasses(): array
    {
        return self::PINNABLE;
    }
}
