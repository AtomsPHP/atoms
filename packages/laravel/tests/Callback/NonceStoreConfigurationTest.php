<?php

declare(strict_types=1);

namespace Atoms\Laravel\Tests\Callback;

use Atoms\Client\Callback\CallbackKernel;
use Atoms\Client\Callback\InMemoryNonceStore;
use Atoms\Client\Callback\NonceStore;
use Atoms\Client\Callback\NonceStoreConfigurationException;
use Atoms\Laravel\AtomsServiceProvider;
use Atoms\Laravel\Tests\TestCase;

final class NonceStoreConfigurationTest extends TestCase
{
    protected bool $withMemoryNonceStore = false;

    public function testUnconfiguredStoreDoesNotPreventBootOrKernelConstruction(): void
    {
        self::assertNull(config('atoms.callback_nonce.driver'));
        self::assertInstanceOf(CallbackKernel::class, $this->app->make(CallbackKernel::class));
        self::assertTrue($this->app->isBooted());
        $this->artisan('list')->assertExitCode(0);

        $this->expectException(NonceStoreConfigurationException::class);
        $this->app->make(NonceStore::class)->seen('nonce');
    }

    public function testExplicitMemoryStoreWorksWithoutSharedStoreConfiguration(): void
    {
        config(['atoms.callback_nonce.driver' => 'memory']);
        $store = $this->app->make(NonceStore::class);
        self::assertFalse($store->seen('nonce'));
        self::assertTrue($store->seen('nonce'));
    }

    public function testDatabaseStoreRequiresANamespaceBeforeConnecting(): void
    {
        config(['atoms.callback_nonce.driver' => 'database']);
        $this->expectException(NonceStoreConfigurationException::class);
        $this->app->make(NonceStore::class)->seen('nonce');
    }

    public function testInvalidDriverIsRejected(): void
    {
        config(['atoms.callback_nonce.driver' => 'array']);
        $this->expectException(NonceStoreConfigurationException::class);
        $this->app->make(NonceStore::class)->seen('nonce');
    }

    public function testProviderPreservesAnExistingCustomNonceStore(): void
    {
        $custom = new InMemoryNonceStore();
        $this->app->instance(NonceStore::class, $custom);
        (new AtomsServiceProvider($this->app))->register();
        self::assertSame($custom, $this->app->make(NonceStore::class));
    }

    public function testRedisUsesTheNamedFrameworkConnectionOnlyWhenClaimingANonce(): void
    {
        config([
            'atoms.callback_nonce.driver' => 'redis',
            'atoms.callback_nonce.namespace' => 'app:testing',
            'atoms.callback_nonce.redis_connection' => 'atoms',
        ]);
        $nativeConnection = $this->createMock(\Predis\Connection\ConnectionInterface::class);
        $nativeConnection->method('getParameters')->willReturn(new \Predis\Connection\Parameters());
        $nativeConnection->expects(self::exactly(2))->method('executeCommand')->willReturnOnConsecutiveCalls(1, 0);
        $native = new \Predis\Client($nativeConnection);
        $connection = new \Illuminate\Redis\Connections\PredisConnection($native);
        $calls = 0;
        $factory = $this->createMock(\Illuminate\Contracts\Redis\Factory::class);
        $factory->expects(self::once())->method('connection')->with('atoms')->willReturnCallback(
            static function () use ($connection, &$calls) {
                $calls++;
                return $connection;
            },
        );
        $this->app->instance(\Illuminate\Contracts\Redis\Factory::class, $factory);
        $this->app->make(CallbackKernel::class);
        $store = $this->app->make(NonceStore::class);
        self::assertSame(0, $calls);
        self::assertFalse($store->seen('nonce'));
        self::assertTrue($store->seen('nonce'));
        self::assertSame(1, $calls);
    }

    public function testInvalidRedisConfigurationDoesNotConnectDuringKernelConstruction(): void
    {
        config([
            'atoms.callback_nonce.driver' => 'redis',
            'atoms.callback_nonce.namespace' => 'app:testing',
            'atoms.callback_nonce.redis_connection' => '',
        ]);
        $this->app->make(CallbackKernel::class);
        $this->expectException(NonceStoreConfigurationException::class);
        $this->app->make(NonceStore::class)->seen('nonce');
    }

    public function testRetentionCannotBeShorterThanTheTimestampWindowRequires(): void
    {
        config([
            'atoms.callback_nonce.driver' => 'redis',
            'atoms.callback_nonce.namespace' => 'app:testing',
            'atoms.callback_nonce.retention_seconds' => 300,
        ]);
        $this->expectException(NonceStoreConfigurationException::class);
        $this->app->make(NonceStore::class)->seen('nonce');
    }

    public function testPruneRequiresDatabaseConfiguration(): void
    {
        self::assertSame(1, \Illuminate\Support\Facades\Artisan::call('atoms:nonces:prune'));
        $output = \Illuminate\Support\Facades\Artisan::output();
        self::assertStringContainsString('ATOMS-E111', $output);
        self::assertStringContainsString('Configure a supported shared nonce store', $output);
    }
}
