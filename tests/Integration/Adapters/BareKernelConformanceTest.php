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

    /**
     * BareKernelHost is deliberately router-free and client-free (see its
     * own class docblock) — 'routing' and 'client' are permanent gaps, not
     * regressions, matching {@see BareKernelHost::supports()} exactly.
     *
     * @return list<string>
     */
    protected function expectedMissingCapabilities(): array
    {
        return ['routing', 'client'];
    }
}
