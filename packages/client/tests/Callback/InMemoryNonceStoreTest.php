<?php

declare(strict_types=1);

namespace Atoms\Client\Tests\Callback;

use Atoms\Client\Callback\InMemoryNonceStore;
use PHPUnit\Framework\TestCase;

final class InMemoryNonceStoreTest extends TestCase
{
    public function testDetectsReplayAndEvictsLru(): void
    {
        $store = new InMemoryNonceStore(2);

        self::assertFalse($store->seen('a'));
        self::assertTrue($store->seen('a'));

        self::assertFalse($store->seen('b'));
        self::assertFalse($store->seen('c')); // evicts 'a' (oldest)
        self::assertFalse($store->seen('a'), 'a was evicted so it reads as new again');
    }
}
