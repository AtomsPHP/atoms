<?php

declare(strict_types=1);

namespace Atoms\Tests\Integration\Adapters;

use Atoms\Tests\Integration\Adapters\Host\AdapterHost;
use Atoms\Tests\Integration\Adapters\Host\BareKernelHost;
use Atoms\Tests\Integration\Adapters\Host\LaravelHost;
use Atoms\Tests\Integration\Adapters\Host\PlainPhpHost;
use Atoms\Tests\Integration\Adapters\Host\SymfonyHost;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CapabilityMatrixTest extends TestCase
{
    /** @return iterable<string, array{AdapterHost, string}> */
    public static function capabilityMutations(): iterable
    {
        foreach ([new BareKernelHost(), new PlainPhpHost(), new LaravelHost(), new SymfonyHost()] as $host) {
            foreach (array_keys(CapabilityMatrix::SUPPORTED_BY) as $capability) {
                yield $host->name() . ':' . $capability => [$host, $capability];
            }
        }
    }

    #[DataProvider('capabilityMutations')]
    public function testEveryCapabilityGainOrLossIsDetected(AdapterHost $host, string $capability): void
    {
        $capabilities = $host->capabilities();
        $capabilities = in_array($capability, $capabilities, true)
            ? array_values(array_diff($capabilities, [$capability]))
            : [...$capabilities, $capability];

        $mutant = $this->createStub(AdapterHost::class);
        $mutant->method('name')->willReturn($host->name());
        $mutant->method('capabilities')->willReturn($capabilities);

        $this->expectException(AssertionFailedError::class);
        CapabilityMatrix::assertHost($mutant);
    }

    public function testNewCapabilityWithoutAnyBehavioralCaseCannotPassSilently(): void
    {
        $matrix = CapabilityMatrix::SUPPORTED_BY;
        $matrix['unexercised-capability'] = ['laravel'];

        $this->expectException(AssertionFailedError::class);
        CapabilityMatrix::assertHost(new LaravelHost(), $matrix);
    }

    public function testRenamedCapabilityCannotPassSilently(): void
    {
        $mutant = $this->createStub(AdapterHost::class);
        $mutant->method('name')->willReturn('laravel');
        $mutant->method('capabilities')->willReturn(['routing', 'container', 'client', 'queue', 'renamed-logging']);

        $this->expectException(AssertionFailedError::class);
        CapabilityMatrix::assertHost($mutant);
    }

    public function testUnknownCaseCapabilityFailsInsteadOfSkipping(): void
    {
        $this->expectException(\LogicException::class);
        CapabilityMatrix::supports(new LaravelHost(), 'unknown');
    }

    public function testCrossHostChecksCapabilitiesThatNoCallbackCaseUses(): void
    {
        foreach (CallbackCases::all() as $case) {
            self::assertNotContains('logging', $case->appliesTo);
        }
        $mutant = $this->createMock(AdapterHost::class);
        $mutant->method('name')->willReturn('laravel');
        $mutant->method('capabilities')->willReturn(['routing', 'container', 'client', 'queue']);
        $mutant->expects(self::never())->method('boot');
        $signer = new Support\CallbackSigner();
        $options = new Host\HostOptions('http://worker.test', $signer->sharedSecretBase64(), '/atoms/callback', []);
        $run = new \ReflectionMethod(CrossHostEquivalenceTest::class, 'runAllCases');

        $this->expectException(AssertionFailedError::class);
        $run->invoke(new CrossHostEquivalenceTest('testAllFourHostsProduceIdenticalStatusAndBodyForEveryCallbackCase'), $mutant, $signer, $options);
    }

    public function testLaravelQueueUnavailableSetupFailsInsteadOfSkipping(): void
    {
        $this->expectException(\LogicException::class);
        (new LaravelHost())->boot(new Host\HostOptions(
            endpoint: 'http://worker.test',
            sharedSecret: base64_encode(str_repeat('x', 32)),
            callbackPath: '/atoms/callback',
            methodsClasses: [],
            queueAvailable: false,
        ));
    }
}
