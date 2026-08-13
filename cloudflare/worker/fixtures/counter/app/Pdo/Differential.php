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
        try {
            $oursOutcome = self::capture($case['run'], $ours);
            $theirsOutcome = self::capture($case['run'], $comparator);
        } catch (\Throwable $e) {
            // The HARNESS broke (a value the normalizer can't handle, a case
            // record malformed) — never the case-under-test's own outcome,
            // and never pinnable (design §2.4).
            return self::shape($case, 'error', '(harness error)', '(harness error)', $e->getMessage());
        }

        if ($case['informational']) {
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
