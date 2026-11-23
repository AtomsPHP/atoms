<?php

declare(strict_types=1);

namespace Atoms\Tests\Integration\Adapters;

use Atoms\Tests\Integration\Adapters\Fixtures\ConventionRoom;
use Atoms\Tests\Integration\Adapters\Host\AdapterHost;
use Atoms\Tests\Integration\Adapters\Host\SymfonyHost;

/**
 * Runs the adapter conformance suite against a real Symfony application —
 * the committed Host/symfony-app fixture, mounting the real
 * Atoms\Symfony\AtomsBundle exactly per packages/symfony/README.md, driven
 * through a real Symfony\Component\HttpKernel\Kernel — see
 * Host/SymfonyHost.php and Host/SymfonyTestKernel.php.
 */
final class SymfonyAdapterConformanceTest extends AdapterConformanceTestCase
{
    protected function createHost(): AdapterHost
    {
        return new SymfonyHost();
    }

    /**
     * Convention-named Methods classes registered as Symfony services
     * (without #[MethodsFor] and without an entry in atoms.methods_classes)
     * must resolve through CallbackKernel's compiled ServiceLocator with
     * constructor injection working end-to-end.
     */
    public function testConventionNamedMethodsServiceAutowiresWithoutMethodsClasses(): void
    {
        $body = CallbackCases::methodsBody(ConventionRoom::class, 'c-1', 'rank', [9]);
        $request = CallbackCases::signedRequest($this->signer, $this->options, 'methods', $body);

        $response = $this->host->handle($request);

        self::assertSame(200, $response->status);
        self::assertSame('{"result":"Score: 9"}', $response->body);
    }
}
