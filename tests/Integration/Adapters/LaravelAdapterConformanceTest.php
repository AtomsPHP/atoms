<?php

declare(strict_types=1);

namespace Atoms\Tests\Integration\Adapters;

use Atoms\Tests\Integration\Adapters\Host\AdapterHost;
use Atoms\Tests\Integration\Adapters\Host\LaravelHost;

/**
 * Runs the adapter conformance suite against a real Laravel application —
 * Orchestra\Testbench\Foundation\Application::create() registering the real
 * Atoms\Laravel\AtomsServiceProvider, driven through the real
 * Illuminate\Contracts\Http\Kernel — see Host/LaravelHost.php.
 */
final class LaravelAdapterConformanceTest extends AdapterConformanceTestCase
{
    protected function createHost(): AdapterHost
    {
        return new LaravelHost();
    }
}
