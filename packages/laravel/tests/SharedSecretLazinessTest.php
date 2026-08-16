<?php

declare(strict_types=1);

namespace Atoms\Laravel\Tests;

use Atoms\Client\AtomsClient;
use Atoms\Client\AtomsConfig;
use Atoms\Client\Callback\CallbackKernel;

/**
 * Boots an app with no shared secret configured, the shape of an application
 * that has the package installed but does not call Atoms yet. Every binding
 * is lazy, so the app boots and serves; the ATOMS-E105 failure arrives at the
 * point a service is actually resolved.
 */
final class SharedSecretLazinessTest extends TestCase
{
    protected bool $withSharedSecret = false;

    public function testTheAppBootsAndRoutesWithoutASecret(): void
    {
        self::assertNull(config('atoms.shared_secret'));
        self::assertTrue($this->app->isBooted());
        self::assertTrue($this->app['router']->has('atoms.callback'));
    }

    public function testArtisanStillRunsWithoutASecret(): void
    {
        $this->artisan('list')->assertExitCode(0);
    }

    public function testResolvingTheConfigThrowsE105(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/ATOMS-E105/');

        $this->app->make(AtomsConfig::class);
    }

    public function testResolvingTheClientThrowsE105(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/ATOMS-E105/');

        $this->app->make(AtomsClient::class);
    }

    public function testResolvingTheCallbackKernelThrowsE105(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/ATOMS-E105/');

        $this->app->make(CallbackKernel::class);
    }
}
