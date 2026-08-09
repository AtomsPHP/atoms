<?php

declare(strict_types=1);

namespace Atoms\Laravel\Tests;

use Atoms\Client\AtomsClient;
use Atoms\Client\AtomsConfig;
use Atoms\Client\Callback\MethodsResolver;
use Atoms\Laravel\AtomsManager;
use Atoms\Laravel\Facades\Atoms;
use Atoms\Laravel\Tests\Fixtures\GameRoom;
use GuzzleHttp\Client as GuzzleClient;
use Psr\Http\Client\ClientInterface;

final class AtomsServiceProviderTest extends TestCase
{
    public function testManagerIsBoundAsASingleton(): void
    {
        $first = $this->app->make(AtomsManager::class);
        $second = $this->app->make(AtomsManager::class);

        self::assertSame($first, $second);
    }

    public function testFacadeResolvesToTheManager(): void
    {
        self::assertInstanceOf(AtomsManager::class, Atoms::getFacadeRoot());
        self::assertSame($this->app->make(AtomsManager::class), Atoms::getFacadeRoot());
    }

    public function testClientIsBoundAndWiredFromConfig(): void
    {
        $client = $this->app->make(AtomsClient::class);

        self::assertInstanceOf(AtomsClient::class, $client);
    }

    public function testHttpClientDefaultsToGuzzleWhenNothingElseIsBound(): void
    {
        self::assertInstanceOf(GuzzleClient::class, $this->app->make(ClientInterface::class));
    }

    /**
     * Atoms is self-hosted in the user's own Cloudflare account, so there is no
     * plausible default endpoint — an empty one the user must fill in is
     * strictly better than a dead host that looks real.
     */
    public function testConfigDefaultsAreMerged(): void
    {
        self::assertSame('', config('atoms.endpoint'));
        self::assertNull(config('atoms.api_key'));
        self::assertSame('/atoms/callback', config('atoms.callback.path'));
        self::assertSame([], config('atoms.callback.middleware'));
        self::assertSame('.atoms/build/manifest.json', config('atoms.manifest_path'));
    }

    public function testConfigChangesFlowIntoAtomsConfig(): void
    {
        config([
            'atoms.endpoint' => 'https://atoms.staging.workers.dev',
            'atoms.api_key' => 'secret-key',
            'atoms.timeout' => 5.5,
            'atoms.max_attempts' => 7,
        ]);
        $this->app->forgetInstance(AtomsConfig::class);

        $atomsConfig = $this->app->make(AtomsConfig::class);

        self::assertSame('https://atoms.staging.workers.dev', $atomsConfig->endpoint);
        self::assertSame('secret-key', $atomsConfig->apiKey);
        self::assertTrue($atomsConfig->isAuthenticated());
        self::assertSame(5.5, $atomsConfig->timeout);
        self::assertSame(7, $atomsConfig->maxAttempts);
    }

    /**
     * An unset ATOMS_API_KEY is the shape that matches a Worker deployed with
     * ATOMS_APP_KEY unset: unauthenticated on purpose, not accidentally empty.
     */
    public function testUnsetApiKeyBecomesAnExplicitlyUnauthenticatedConfig(): void
    {
        config([
            'atoms.endpoint' => 'http://127.0.0.1:8787',
            'atoms.api_key' => null,
        ]);
        $this->app->forgetInstance(AtomsConfig::class);

        $atomsConfig = $this->app->make(AtomsConfig::class);

        self::assertNull($atomsConfig->apiKey);
        self::assertFalse($atomsConfig->isAuthenticated());
    }

    public function testEmptyApiKeyIsRejectedRatherThanTreatedAsUnauthenticated(): void
    {
        config([
            'atoms.endpoint' => 'http://127.0.0.1:8787',
            'atoms.api_key' => '',
        ]);
        $this->app->forgetInstance(AtomsConfig::class);

        $this->expectException(\InvalidArgumentException::class);

        $this->app->make(AtomsConfig::class);
    }

    public function testCallbackRouteIsRegistered(): void
    {
        self::assertTrue($this->app['router']->has('atoms.callback'));
    }

    public function testMethodsResolverBuildsTypeMapFromManifestWhenPresent(): void
    {
        config(['atoms.manifest_path' => __DIR__ . '/Fixtures/manifest.json']);
        $this->app->forgetInstance(MethodsResolver::class);

        $resolver = $this->app->make(MethodsResolver::class);

        self::assertSame(GameRoom\Methods::class, $resolver->resolve('GameRoom'));
    }

    public function testMethodsResolverDegradesGracefullyWithoutAManifest(): void
    {
        config(['atoms.manifest_path' => __DIR__ . '/Fixtures/does-not-exist.json']);
        $this->app->forgetInstance(MethodsResolver::class);

        $resolver = $this->app->make(MethodsResolver::class);

        self::assertSame(GameRoom\Methods::class, $resolver->resolve(GameRoom::class));
    }
}
