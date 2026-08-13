<?php

declare(strict_types=1);

namespace Atoms\Tests\Integration\Adapters;

use Atoms\Tests\Integration\Adapters\Host\AdapterHost;
use Atoms\Tests\Integration\Adapters\Host\PlainPhpHost;

/**
 * Runs the adapter conformance suite against the plain-PHP example's own
 * AtomsBootstrap::create() + PlainPhpApp::handleGlobals() front controller —
 * see examples/AGENTS.md.
 */
final class PlainPhpAdapterConformanceTest extends AdapterConformanceTestCase
{
    protected function createHost(): AdapterHost
    {
        return new PlainPhpHost();
    }
}
