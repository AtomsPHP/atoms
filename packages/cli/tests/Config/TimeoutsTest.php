<?php

declare(strict_types=1);

namespace Atoms\Cli\Tests\Config;

use Atoms\Cli\Config\Timeouts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TimeoutsTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('ATOMS_COMPOSER_TIMEOUT');
    }

    public function testUnsetUsesTheDefault(): void
    {
        putenv('ATOMS_COMPOSER_TIMEOUT');

        self::assertSame(600.0, Timeouts::composerInstall());
    }

    public function testASetValueWins(): void
    {
        putenv('ATOMS_COMPOSER_TIMEOUT=30');

        self::assertSame(30.0, Timeouts::composerInstall());
    }

    #[DataProvider('unusableValues')]
    public function testAnUnusableValueRefusesInsteadOfDefaultingOrReachingTheProcessRunner(string $value): void
    {
        putenv('ATOMS_COMPOSER_TIMEOUT=' . $value);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ATOMS_COMPOSER_TIMEOUT');

        Timeouts::composerInstall();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableValues(): iterable
    {
        yield 'negative' => ['-1'];
        yield 'zero' => ['0'];
        yield 'not a number' => ['soon'];
        yield 'infinite' => ['INF'];
    }
}
