<?php

declare(strict_types=1);

namespace Atoms\Tests\Integration\Adapters;

use Atoms\Tests\Integration\Adapters\Fixtures\GameRoom\Methods as GameRoomMethods;
use Atoms\Tests\Integration\Adapters\Host\AdapterHost;
use Atoms\Tests\Integration\Adapters\Host\BareKernelHost;
use Atoms\Tests\Integration\Adapters\Host\HostOptions;
use Atoms\Tests\Integration\Adapters\Host\LaravelHost;
use Atoms\Tests\Integration\Adapters\Host\PlainPhpHost;
use Atoms\Tests\Integration\Adapters\Host\SymfonyHost;
use Atoms\Tests\Integration\Adapters\Support\CallbackSigner;
use PHPUnit\Framework\TestCase;

/**
 * The central assertion this test proves: for every {@see CallbackCases} row,
 * the [status, body] pair bare-kernel, plain-PHP, Laravel and Symfony each
 * produce must be PAIRWISE IDENTICAL — the four hosts are provably
 * equivalent callback-channel implementations, not four independently
 * plausible ones, and not a suite that merely passes because every host
 * secretly shares one fake.
 *
 * Pairwise comparison runs BEFORE this test also checks each host's result
 * against the case's own expected constant (below). These two passes catch
 * DIFFERENT bugs and neither substitutes for the other:
 *
 *  - Pairwise comparison localizes divergence BETWEEN hosts — it proves
 *    equivalence, and a mismatch points straight at which host disagrees.
 *    But it is mathematically blind to a wrong answer all four hosts share:
 *    the four hosts wrap ONE kernel ({@see \Atoms\Client\Callback\CallbackKernel}),
 *    so a bug in the kernel itself (or in this table) reproduces identically
 *    on every host, and identical wrongness is invisible to a check that
 *    only asks "do they agree with each other".
 *  - The expected-constant pass below is what catches THAT: it compares
 *    every host's result to {@see CallbackCases}' own expected body, which
 *    is independent of what any host actually returned. This was proven
 *    empirically — appending garbage to every message
 *    `CallbackKernel::error()` builds kept this test's pairwise pass green
 *    (all four hosts still agreed with each other, just on the corrupted
 *    text) and was only caught once every row's expected body became an
 *    EXACT comparison here and in the per-host suites (status +
 *    error.code + a message substring let 11 of 15 rows through
 *    uncorrupted-looking). {@see CallbackCase}'s docblock has the full
 *    account.
 *
 * So: pairwise comparison and the expected-constant pass are complementary,
 * not redundant — one needs the other to catch a shared-kernel bug, and
 * dropping either narrows what this test can find.
 *
 * Hosts boot-use-shutdown SEQUENTIALLY, one at a time — collecting a
 * [case key => [status, body]] map per host before moving to the next —
 * never two hosts alive at once, so no framework's static/global state
 * (Laravel's Facade root, Symfony's $_ENV-driven config) can bleed from one
 * host's run into another's.
 *
 * A capability-gated case that a host skips produces NO entry in that
 * host's results map, and both {@see self::assertPairAgrees()} and the
 * per-host pass below quietly treat a missing entry as "nothing to compare"
 * — which is correct for a host that GENUINELY lacks the capability, but
 * gives zero signal for a host that lost it by accident (a `supports()` bug,
 * a boot-path regression). Proven empirically: temporarily forcing
 * `LaravelHost::supports()` to return false unconditionally dropped this
 * test's assertion count 166→161 and the suite stayed green. {@see
 * self::EXPECTED_SKIPS} closes that hole: every case with a non-empty
 * {@see CallbackCase::$appliesTo} must have an entry naming EXACTLY which
 * hosts are expected to skip it (possibly none), and {@see
 * self::assertSkipsMatchExpected()} fails loudly the moment the actually-
 * observed skips stop matching that list — in either direction.
 */
final class CrossHostEquivalenceTest extends TestCase
{
    /**
     * Every {@see CallbackCases}' case with a non-empty `appliesTo` MUST
     * have an entry here, even an empty list — a case missing from this map
     * entirely is treated as a bug in the map, not a silent "expect nothing".
     * Today only 'job-happy' (`appliesTo: ['queue']`) is gated, and all four
     * hosts currently declare 'queue' support, so its own list is empty: NO
     * host is expected to skip it. Adding a new gated case means deciding,
     * here, which real hosts genuinely lack the capability — never widening
     * this map just to make a newly-observed skip pass.
     *
     * @var array<string, list<string>> case key => host names allowed to skip it
     */
    private const EXPECTED_SKIPS = [
        'job-happy' => [],
    ];

    /**
     * @return array<string, \Closure(): AdapterHost>
     */
    private static function hostFactories(): array
    {
        return [
            'bare-kernel' => static fn (): AdapterHost => new BareKernelHost(),
            'plain-php' => static fn (): AdapterHost => new PlainPhpHost(),
            'laravel' => static fn (): AdapterHost => new LaravelHost(),
            'symfony' => static fn (): AdapterHost => new SymfonyHost(),
        ];
    }

    public function testAllFourHostsProduceIdenticalStatusAndBodyForEveryCallbackCase(): void
    {
        $signer = new CallbackSigner();
        $options = new HostOptions(
            endpoint: 'http://worker.test',
            sharedSecret: $signer->sharedSecretBase64(),
            callbackPath: '/atoms/callback',
            methodsClasses: [GameRoomMethods::class],
        );

        /** @var array<string, array<string, array{int, string}>> $resultsByHost case key => [status, body], per host */
        $resultsByHost = [];

        /** @var list<array{0: string, 1: string}> $actualSkips [hostName, caseKey] pairs actually skipped */
        $actualSkips = [];

        foreach (self::hostFactories() as $hostName => $factory) {
            $resultsByHost[$hostName] = $this->runAllCases($factory(), $signer, $options, $hostName, $actualSkips);
        }

        $this->assertSkipsMatchExpected($actualSkips);

        $hostNames = array_keys($resultsByHost);
        $cases = CallbackCases::all();

        foreach ($cases as $case) {
            for ($i = 0; $i < count($hostNames); $i++) {
                for ($j = $i + 1; $j < count($hostNames); $j++) {
                    $this->assertPairAgrees($resultsByHost, $hostNames[$i], $hostNames[$j], $case->key);
                }
            }
        }

        // Only after every host has been checked against every other host:
        // each host's own result against CallbackCases' expected constant
        // for that case (a coarser check than testCallbackCase()'s, which
        // already covers this per host — this is a second, independent pass
        // over the SAME collected results, not a replacement for it).
        foreach ($cases as $case) {
            foreach ($hostNames as $hostName) {
                if (!array_key_exists($case->key, $resultsByHost[$hostName])) {
                    continue;
                }

                [$status, $body] = $resultsByHost[$hostName][$case->key];

                self::assertSame(
                    $case->expectedStatus,
                    $status,
                    "case '{$case->key}': host '{$hostName}' status vs CallbackCases' expected constant",
                );

                self::assertSame(
                    $case->expectedBody,
                    $body,
                    "case '{$case->key}': host '{$hostName}' body vs CallbackCases' expected constant "
                    . "— expected {$case->expectedBody}, got {$body}",
                );
            }
        }
    }

    /**
     * @param array<string, array<string, array{int, string}>> $resultsByHost
     */
    private function assertPairAgrees(array $resultsByHost, string $hostA, string $hostB, string $caseKey): void
    {
        if (!array_key_exists($caseKey, $resultsByHost[$hostA]) || !array_key_exists($caseKey, $resultsByHost[$hostB])) {
            // One (or both) hosts lack a capability this case requires (see
            // CallbackCase::$appliesTo) — nothing to compare for this pair.
            return;
        }

        [$statusA, $bodyA] = $resultsByHost[$hostA][$caseKey];
        [$statusB, $bodyB] = $resultsByHost[$hostB][$caseKey];

        self::assertSame(
            ['status' => $statusA, 'body' => $bodyA],
            ['status' => $statusB, 'body' => $bodyB],
            "case '{$caseKey}': host '{$hostA}' and host '{$hostB}' disagree "
            . "(status {$statusA} vs {$statusB}; body " . json_encode($bodyA) . ' vs ' . json_encode($bodyB) . ')',
        );
    }

    /**
     * @param list<array{0: string, 1: string}> $actualSkips [hostName, caseKey] pairs, appended to by reference
     * @return array<string, array{int, string}> case key => [status, body]
     */
    private function runAllCases(AdapterHost $host, CallbackSigner $signer, HostOptions $options, string $hostName, array &$actualSkips): array
    {
        $host->boot($options);

        try {
            $results = [];

            foreach (CallbackCases::all() as $case) {
                if (!$this->hostSupportsCase($host, $case)) {
                    if ($case->appliesTo !== []) {
                        $actualSkips[] = [$hostName, $case->key];
                    }

                    continue;
                }

                // Freshly built/signed per host: nonce stores are per-host,
                // so the replayed-nonce case's pair of sends must originate
                // from THIS host's own build (primeFirst below), never a
                // request object shared across hosts.
                $request = ($case->build)($signer, $options);

                if ($case->primeFirst) {
                    $host->handle($request);
                }

                $response = $host->handle($request);

                $results[$case->key] = [$response->status, $response->body];
            }

            return $results;
        } finally {
            $host->shutdown();
        }
    }

    private function hostSupportsCase(AdapterHost $host, CallbackCase $case): bool
    {
        foreach ($case->appliesTo as $capability) {
            if (!$host->supports($capability)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The actual gate: the (host, case) pairs skipped during THIS run must
     * equal {@see self::EXPECTED_SKIPS} exactly. A pair present but not
     * expected is a silent capability drop with no other signal (see the
     * class docblock's empirical proof); a pair expected but not observed
     * means EXPECTED_SKIPS itself is stale.
     *
     * @param list<array{0: string, 1: string}> $actualSkips
     */
    private function assertSkipsMatchExpected(array $actualSkips): void
    {
        foreach (CallbackCases::all() as $case) {
            if ($case->appliesTo !== [] && !array_key_exists($case->key, self::EXPECTED_SKIPS)) {
                self::fail(
                    "case '{$case->key}' is capability-gated (appliesTo: " . implode(', ', $case->appliesTo)
                    . ') but has no entry in CrossHostEquivalenceTest::EXPECTED_SKIPS. Add one naming exactly '
                    . 'which hosts are expected to skip it (an empty list if none are).',
                );
            }
        }

        $expectedPairs = [];
        foreach (self::EXPECTED_SKIPS as $caseKey => $hostNames) {
            foreach ($hostNames as $hostName) {
                $expectedPairs[] = "{$hostName}:{$caseKey}";
            }
        }

        $actualPairs = array_map(
            static fn (array $pair): string => "{$pair[0]}:{$pair[1]}",
            $actualSkips,
        );

        sort($expectedPairs);
        sort($actualPairs);

        self::assertSame(
            $expectedPairs,
            $actualPairs,
            "Capability-gated skips diverged from CrossHostEquivalenceTest::EXPECTED_SKIPS.\n"
            . 'Expected (host:case): ' . (($expectedPairs === []) ? '(none)' : implode(', ', $expectedPairs)) . "\n"
            . 'Actual   (host:case): ' . (($actualPairs === []) ? '(none)' : implode(', ', $actualPairs)) . "\n"
            . 'An extra ACTUAL pair means a host silently lost a capability it should have (a real regression — '
            . 'do not "fix" this by adding the pair to EXPECTED_SKIPS). A missing EXPECTED pair means a host '
            . 'gained a capability EXPECTED_SKIPS still assumes it lacks — update the map deliberately.',
        );
    }
}
