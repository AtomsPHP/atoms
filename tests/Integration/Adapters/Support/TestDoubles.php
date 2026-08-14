<?php

declare(strict_types=1);

namespace Atoms\Tests\Integration\Adapters\Support;

use Atoms\Client\Callback\InMemoryNonceStore;
use Atoms\Client\Callback\NonceStore;
use Psr\Http\Client\ClientInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Static registry {@see \Atoms\Tests\Integration\Adapters\Host\symfony-app}'s
 * config/services.php reads from at container-build time. SymfonyHost
 * populates these fields BEFORE constructing/booting the kernel for a given
 * case, so the fixture app's own (committed) service definitions — not a
 * test-only shortcut bolted on afterward — are what wire the doubles in,
 * exactly the way CallbackKernel expects for a routing-capable host: a real
 * ClientInterface, a real 'logger' service, and (only when queue-capable) a
 * real MessageBusInterface for MessengerBridgePass to upgrade.
 *
 * Reset between boots by {@see \Atoms\Tests\Integration\Adapters\Host\SymfonyHost::boot()}
 * so a stale double from a previous case can never leak into the next one.
 */
final class TestDoubles
{
    public static ?ClientInterface $client = null;

    public static ?RecordingLogger $logger = null;

    public static ?NonceStore $nonceStore = null;

    public static ?MessageBusInterface $bus = null;

    public static bool $queueAvailable = true;

    private function __construct()
    {
    }

    public static function client(): ClientInterface
    {
        return self::$client ?? throw new \LogicException(
            'TestDoubles::$client was not set before the Symfony kernel booted.',
        );
    }

    public static function logger(): RecordingLogger
    {
        return self::$logger ?? throw new \LogicException(
            'TestDoubles::$logger was not set before the Symfony kernel booted.',
        );
    }

    /**
     * Falls back to a fresh {@see InMemoryNonceStore} rather than throwing:
     * most cases don't supply a NonceStore override (HostOptions::$nonceStore
     * is null), and that null means "use the bundle's normal default," not
     * "misconfigured."
     */
    public static function nonceStore(): NonceStore
    {
        return self::$nonceStore ?? new InMemoryNonceStore();
    }

    public static function bus(): MessageBusInterface
    {
        return self::$bus ?? throw new \LogicException(
            'TestDoubles::$bus was not set before the Symfony kernel booted.',
        );
    }

    public static function reset(): void
    {
        self::$client = null;
        self::$logger = null;
        self::$nonceStore = null;
        self::$bus = null;
        self::$queueAvailable = true;
    }
}
