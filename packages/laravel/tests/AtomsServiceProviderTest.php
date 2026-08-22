<?php

declare(strict_types=1);

namespace Atoms\Laravel\Tests;

use Atoms\Client\AtomsClient;
use Atoms\Client\AtomsConfig;
use Atoms\Client\Callback\MethodsResolver;
use Atoms\Client\Tickets\TicketIssuer;
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

    public function testTicketIssuerResolvesFromTheContainerAndIsUsable(): void
    {
        $issuer = $this->app->make(TicketIssuer::class);

        self::assertInstanceOf(TicketIssuer::class, $issuer);

        $ticket = $issuer->issue('GameRoom', 'g-1');

        self::assertStringStartsWith('v1.', $ticket->ticket);
    }

    public function testConfiguredWsTicketTtlMsReachesAtomsConfig(): void
    {
        config(['atoms.ws_ticket_ttl_ms' => 15000]);
        $this->app->forgetInstance(AtomsConfig::class);

        $atomsConfig = $this->app->make(AtomsConfig::class);

        self::assertSame(15000, $atomsConfig->wsTicketTtlMs);
    }

    /**
     * Atoms is self-hosted in the user's own Cloudflare account, so there is no
     * plausible default endpoint — an empty one the user must fill in is
     * strictly better than a dead host that looks real.
     */
    public function testConfigDefaultsAreMerged(): void
    {
        self::assertSame('', config('atoms.endpoint'));
        self::assertNull(config('atoms.shared_secret_previous'));
        self::assertSame('/atoms/callback', config('atoms.callback.path'));
        self::assertSame([], config('atoms.callback.middleware'));
        self::assertSame('.atoms/build/manifest.json', config('atoms.manifest_path'));
        self::assertSame(60000, config('atoms.ws_ticket_ttl_ms'));
    }

    public function testConfigChangesFlowIntoAtomsConfig(): void
    {
        config([
            'atoms.endpoint' => 'https://atoms.staging.workers.dev',
            'atoms.shared_secret' => self::SHARED_SECRET,
            'atoms.timeout' => 5.5,
            'atoms.max_attempts' => 7,
        ]);
        $this->app->forgetInstance(AtomsConfig::class);

        $atomsConfig = $this->app->make(AtomsConfig::class);

        self::assertSame('https://atoms.staging.workers.dev', $atomsConfig->endpoint);
        self::assertSame(self::SHARED_SECRET, $atomsConfig->sharedSecret);
        self::assertSame('Dx6RY9LS43pOQhM4PMdaUWx3lk9mfyiiJZFfJtvl9E0=', $atomsConfig->bearerToken());
        self::assertSame(5.5, $atomsConfig->timeout);
        self::assertSame(7, $atomsConfig->maxAttempts);
    }

    public function testRotationOverlapFlowsIntoAtomsConfig(): void
    {
        config([
            'atoms.endpoint' => 'http://127.0.0.1:8787',
            'atoms.shared_secret' => self::SHARED_SECRET,
            'atoms.shared_secret_previous' => base64_encode(str_repeat("\x02", 32)),
        ]);
        $this->app->forgetInstance(AtomsConfig::class);

        $atomsConfig = $this->app->make(AtomsConfig::class);

        self::assertCount(2, $atomsConfig->callbackKeys());
        self::assertSame('Dx6RY9LS43pOQhM4PMdaUWx3lk9mfyiiJZFfJtvl9E0=', $atomsConfig->bearerToken());
    }

    /**
     * The secret is required, and the failure lands where the service is
     * resolved (ATOMS-E105) — SharedSecretLazinessTest covers the other half
     * of that contract.
     */
    public function testMissingSecretThrowsWhenTheConfigServiceIsResolved(): void
    {
        config([
            'atoms.endpoint' => 'http://127.0.0.1:8787',
            'atoms.shared_secret' => null,
        ]);
        $this->app->forgetInstance(AtomsConfig::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/ATOMS-E105/');

        $this->app->make(AtomsConfig::class);
    }

    public function testMalformedSecretThrowsWhenTheConfigServiceIsResolved(): void
    {
        config([
            'atoms.endpoint' => 'http://127.0.0.1:8787',
            'atoms.shared_secret' => 'not-base64-32-bytes',
        ]);
        $this->app->forgetInstance(AtomsConfig::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/ATOMS-E105/');

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

    /**
     * A manifest that exists but does not parse reaches the loader and throws,
     * so it exercises the adapter's catch rather than the is_file() guard: the
     * binding still resolves, and convention-based lookup still works. Only the
     * manifest-derived wire types are lost.
     */
    public function testMethodsResolverDegradesGracefullyOnAnUnparseableManifest(): void
    {
        config(['atoms.manifest_path' => __DIR__ . '/Fixtures/unparseable-manifest.json']);
        $this->app->forgetInstance(MethodsResolver::class);

        $resolver = $this->app->make(MethodsResolver::class);

        self::assertInstanceOf(MethodsResolver::class, $resolver);
        self::assertSame(GameRoom\Methods::class, $resolver->resolve(GameRoom::class));
        self::assertNull($resolver->resolve('GameRoom'));
    }
}
