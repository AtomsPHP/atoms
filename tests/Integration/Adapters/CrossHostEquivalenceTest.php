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
 * The milestone's earning assertion: for every {@see CallbackCases} row, the
 * [status, body] pair bare-kernel, plain-PHP, Laravel and Symfony each
 * produce must be PAIRWISE IDENTICAL — the four hosts are provably
 * equivalent callback-channel implementations, not four independently
 * plausible ones (see AGENTS.md's named failure mode: "a suite that passes
 * because hosts share a fake").
 *
 * Pairwise comparison runs BEFORE this test also checks each host's result
 * against the case's own expected constant (below): a bug that made every
 * host agree on the SAME wrong answer would still fail the pairwise check
 * even in the hypothetical case the per-host suites (which each already
 * assert against CallbackCases' expected constants independently) somehow
 * didn't catch it.
 *
 * Hosts boot-use-shutdown SEQUENTIALLY, one at a time — collecting a
 * [case key => [status, body]] map per host before moving to the next —
 * never two hosts alive at once, so no framework's static/global state
 * (Laravel's Facade root, Symfony's $_ENV-driven config) can bleed from one
 * host's run into another's.
 */
final class CrossHostEquivalenceTest extends TestCase
{
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
            apiKey: 'k',
            publicKey: $signer->publicKeyBase64(),
            callbackPath: '/atoms/callback',
            methodsClasses: [GameRoomMethods::class],
        );

        /** @var array<string, array<string, array{int, string}>> $resultsByHost case key => [status, body], per host */
        $resultsByHost = [];

        foreach (self::hostFactories() as $hostName => $factory) {
            $resultsByHost[$hostName] = $this->runAllCases($factory(), $signer, $options);
        }

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

                if ($case->bodyAssertion === 'exact') {
                    self::assertSame(
                        $case->expectedBody,
                        $body,
                        "case '{$case->key}': host '{$hostName}' body vs CallbackCases' expected constant",
                    );
                }
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
     * @return array<string, array{int, string}> case key => [status, body]
     */
    private function runAllCases(AdapterHost $host, CallbackSigner $signer, HostOptions $options): array
    {
        $host->boot($options);

        try {
            $results = [];

            foreach (CallbackCases::all() as $case) {
                if (!$this->hostSupportsCase($host, $case)) {
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
}
