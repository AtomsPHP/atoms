<?php

declare(strict_types=1);

namespace Atoms\Tests\Integration\Adapters;

use Atoms\Tests\Integration\Adapters\Host\AdapterHost;
use PHPUnit\Framework\Assert;

final class CapabilityMatrix
{
    /** @var array<string, list<string>> capability => hosts expected to support it */
    public const SUPPORTED_BY = [
        'routing' => ['plain-php', 'laravel', 'symfony'],
        'container' => ['bare-kernel', 'plain-php', 'laravel', 'symfony'],
        'client' => ['plain-php', 'laravel', 'symfony'],
        'queue' => ['bare-kernel', 'plain-php', 'laravel', 'symfony'],
        'logging' => ['bare-kernel', 'plain-php', 'laravel', 'symfony'],
        // Can boot without a queue to test dispatch failure.
        'no-queue' => ['bare-kernel', 'plain-php', 'symfony'],
    ];

    /**
     * Check capabilities even when no behavioral test exercises them yet.
     *
     * @param array<string, list<string>> $matrix
     */
    public static function assertHost(AdapterHost $host, array $matrix = self::SUPPORTED_BY): void
    {
        $expected = [];
        foreach ($matrix as $capability => $hosts) {
            if (in_array($host->name(), $hosts, true)) {
                $expected[] = $capability;
            }
        }
        $actual = $host->capabilities();
        sort($expected);
        sort($actual);

        Assert::assertSame($expected, $actual, "Host '{$host->name()}' capabilities differ from the declared matrix.");
    }

    public static function supports(AdapterHost $host, string $capability): bool
    {
        if (!array_key_exists($capability, self::SUPPORTED_BY)) {
            throw new \LogicException("Unknown adapter capability '{$capability}'.");
        }

        return in_array($capability, $host->capabilities(), true);
    }
}
