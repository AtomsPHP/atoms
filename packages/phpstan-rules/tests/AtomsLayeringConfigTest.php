<?php

declare(strict_types=1);

namespace Atoms\PHPStan\Tests;

use Atoms\PHPStan\AtomsLayeringConfig;
use Atoms\PHPStan\Zone;
use PHPUnit\Framework\TestCase;

/**
 * Covers the surface that changed shape: `zones()` and `zonesContaining()`
 * hand back {@see Zone} objects built once from the raw neon array, rather
 * than the raw array itself.
 */
final class AtomsLayeringConfigTest extends TestCase
{
    public function testZonesAreBuiltFromTheRawNeonArrayShape(): void
    {
        $config = self::config();

        $zones = $config->zones();

        self::assertCount(2, $zones);
        self::assertContainsOnlyInstancesOf(Zone::class, $zones);
        self::assertTrue($zones[0]->isForbidden('Illuminate\Support\Str'));
    }

    public function testEmptyConfigurationHasNoZones(): void
    {
        $config = new AtomsLayeringConfig();

        self::assertSame([], $config->zones());
        self::assertSame([], $config->zonesContaining('/repo/packages/core/src/Atom.php'));
        self::assertSame([], $config->forbiddenFunctions());
    }

    public function testZonesContainingReturnsOnlyTheZonesCoveringTheFile(): void
    {
        $config = self::config();

        $matches = $config->zonesContaining('/repo/packages/core/src/Atom.php');

        self::assertCount(1, $matches);
        self::assertTrue($matches[0]->isForbidden('Illuminate\Support\Str'));
        self::assertFalse($matches[0]->isForbidden('Doctrine\ORM\Query'));
    }

    /**
     * A file can legitimately fall under more than one zone (a package path
     * nested under a broader one), and every match is returned — the rule
     * checks the file against all of them.
     */
    public function testZonesContainingReturnsEveryOverlappingZone(): void
    {
        $config = self::config();

        $matches = $config->zonesContaining('/repo/packages/core/src/Nested/Thing.php');

        self::assertCount(2, $matches);
        self::assertTrue($matches[0]->isForbidden('Illuminate\Support\Str'));
        self::assertFalse($matches[0]->isForbidden('Doctrine\ORM\Query'));
        self::assertTrue($matches[1]->isForbidden('Doctrine\ORM\Query'));
    }

    public function testFileUnderNoZoneMatchesNothing(): void
    {
        $config = self::config();

        self::assertSame([], $config->zonesContaining('/repo/packages/cli/src/Application.php'));
    }

    public function testForbiddenFunctionsArePassedThroughUntouched(): void
    {
        self::assertSame(['config', 'app'], self::config()->forbiddenFunctions());
    }

    private static function config(): AtomsLayeringConfig
    {
        return new AtomsLayeringConfig(
            zones: [
                [
                    'paths' => ['packages/core/src'],
                    'forbid' => ['Illuminate'],
                    'allow' => [],
                ],
                [
                    'paths' => ['packages/core/src/Nested'],
                    'forbid' => ['Doctrine'],
                    'allow' => [],
                ],
            ],
            forbiddenFunctions: ['config', 'app'],
        );
    }
}
