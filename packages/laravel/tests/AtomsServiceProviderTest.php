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

    public function testConfigDefaultsAreMerged(): void
    {
        self::assertSame('https://api.atoms.cloud', config('atoms.endpoint'));
        self::assertSame('/atoms/callback', config('atoms.callback.path'));
        self::assertSame([], config('atoms.callback.middleware'));
        self::assertSame('.atoms/build/manifest.json', config('atoms.manifest_path'));
    }

    public function testConfigChangesFlowIntoAtomsConfigMappingProjectToCustomer(): void
    {
        config([
            'atoms.project' => 'acme-games',
            'atoms.endpoint' => 'https://staging.atoms.cloud',
            'atoms.api_key' => 'secret-key',
            'atoms.timeout' => 5.5,
            'atoms.max_attempts' => 7,
        ]);
        $this->app->forgetInstance(AtomsConfig::class);

        $atomsConfig = $this->app->make(AtomsConfig::class);

        self::assertSame('acme-games', $atomsConfig->customer);
        self::assertSame('https://staging.atoms.cloud', $atomsConfig->endpoint);
        self::assertSame('secret-key', $atomsConfig->apiKey);
        self::assertSame(5.5, $atomsConfig->timeout);
        self::assertSame(7, $atomsConfig->maxAttempts);
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
