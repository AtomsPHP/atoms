<?php

declare(strict_types=1);

namespace Atoms\Client\Tests\Callback;

use Atoms\Client\Callback\InMemoryNonceStore;
use Atoms\Client\Callback\LazyNonceStore;
use Atoms\Client\Callback\NonceStore;
use Atoms\Client\Callback\NonceStoreUnavailableException;
use PHPUnit\Framework\TestCase;

final class LazyNonceStoreTest extends TestCase
{
    public function testConstructionIsDeferredAndTheStoreIsReused(): void
    {
        $calls = 0;
        $store = new LazyNonceStore(static function () use (&$calls): NonceStore {
            ++$calls;
            return new InMemoryNonceStore();
        });
        self::assertSame(0, $calls);
        self::assertFalse($store->seen('nonce'));
        self::assertTrue($store->seen('nonce'));
        self::assertSame(1, $calls);
    }

    public function testConnectionFailureDoesNotInstallAnEmptyFallback(): void
    {
        $calls = 0;
        $store = new LazyNonceStore(static function () use (&$calls): NonceStore {
            ++$calls;
            throw new \RuntimeException('secret connection details');
        });
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            try {
                $store->seen('nonce');
                self::fail('The unavailable store must fail every claim.');
            } catch (NonceStoreUnavailableException $error) {
                self::assertStringNotContainsString('secret', $error->getMessage());
            }
        }
        self::assertSame(2, $calls);
    }
}
