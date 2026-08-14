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

    /**
     * LaravelHost::supports() reports all five capabilities — nothing here
     * for {@see AdapterConformanceTestCase::failOrSkipMissingCapability()}
     * to ever declare. Laravel's one known skip (S4, "queue unavailable") is
     * a DIFFERENT mechanism: LaravelHost::boot() itself calls
     * `Assert::markTestSkipped()` when asked to boot queueless, because
     * LaravelQueueBridge wraps `Illuminate\Contracts\Bus\Dispatcher`
     * unconditionally — Laravel always has a bus, so that premise cannot be
     * represented here at all, capability-supported-or-not. That skip is
     * self-documenting at its own call site and isn't a capability gap this
     * list needs to name.
     *
     * @return list<string>
     */
    protected function expectedMissingCapabilities(): array
    {
        return [];
    }
}
