<?php

declare(strict_types=1);

namespace Atoms\Tests\Integration\Adapters;

use Atoms\Tests\Integration\Adapters\Host\AdapterHost;
use Atoms\Tests\Integration\Adapters\Host\BareKernelHost;

/**
 * Runs the adapter conformance suite against the framework-free reference
 * host: CallbackKernelFactory::create() wired directly.
 */
final class BareKernelConformanceTest extends AdapterConformanceTestCase
{
    protected function createHost(): AdapterHost
    {
        return new BareKernelHost();
    }
}
