<?php

declare(strict_types=1);

namespace Atoms\Client\Tests\Callback;

use Atoms\Client\Callback\NonceStoreConfigurationException;
use Atoms\Client\Callback\NonceStoreOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NonceStoreOptionsTest extends TestCase
{
    public function testRetentionCoversBothTimestampDirectionsAndClockAllowance(): void
    {
        $options = NonceStoreOptions::fromArray(['namespace' => 'app:production'], 300);
        self::assertSame(661, $options->retentionSeconds);
        self::assertSame(1201, NonceStoreOptions::fromArray([
            'namespace' => 'app:production', 'clock_allowance_seconds' => 0,
        ], 600)->retentionSeconds);
        self::assertSame(900, NonceStoreOptions::fromArray([
            'namespace' => 'app:production', 'retention_seconds' => '900',
        ], 300)->retentionSeconds);
    }

    public function testKeysAreStableAcrossInstancesAndSeparateNamespaces(): void
    {
        $first = NonceStoreOptions::fromArray(['namespace' => 'app:production'], 300);
        $second = NonceStoreOptions::fromArray(['namespace' => 'app:production'], 600);
        $other = NonceStoreOptions::fromArray(['namespace' => 'app:staging'], 300);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first->key('nonce'));
        self::assertSame($first->key('nonce'), $second->key('nonce'));
        self::assertNotSame($first->key('nonce'), $other->key('nonce'));
        self::assertNotSame($first->key('nonce'), $first->key('another'));
    }

    public static function invalidOptions(): iterable
    {
        yield 'missing namespace' => [[], 300];
        yield 'blank namespace' => [['namespace' => '  '], 300];
        yield 'namespace separator' => [['namespace' => "a\0b"], 300];
        yield 'negative window' => [['namespace' => 'app'], -1];
        yield 'negative allowance' => [['namespace' => 'app', 'clock_allowance_seconds' => -1], 300];
        yield 'float allowance' => [['namespace' => 'app', 'clock_allowance_seconds' => 1.5], 300];
        yield 'short retention' => [['namespace' => 'app', 'retention_seconds' => 660], 300];
        yield 'overflow window' => [['namespace' => 'app'], PHP_INT_MAX];
        yield 'overflow allowance' => [['namespace' => 'app', 'clock_allowance_seconds' => PHP_INT_MAX], 300];
        yield 'overflow retention' => [['namespace' => 'app', 'retention_seconds' => PHP_INT_MAX], 300];
        yield 'invalid retention' => [['namespace' => 'app', 'retention_seconds' => 'forever'], 300];
    }

    #[DataProvider('invalidOptions')]
    public function testRejectsInvalidOptions(array $config, int $window): void
    {
        $this->expectException(NonceStoreConfigurationException::class);
        $this->expectExceptionMessage('ATOMS-E111');
        NonceStoreOptions::fromArray($config, $window);
    }
}
