<?php

declare(strict_types=1);

namespace Atoms\Tests\Integration\Adapters;

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
}
