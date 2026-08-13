<?php

declare(strict_types=1);

namespace Atoms\PHPStan\Tests;

use Atoms\PHPStan\PathMatcher;
use Atoms\PHPStan\Rules\LayeringRule;
use PHPStan\Rules\LazyRegistry;
use PHPStan\Testing\PHPStanTestCase;

/**
 * The wiring proof: loads the REPOSITORY ROOT phpstan.neon.dist (not
 * layering.neon in isolation) through PHPStan's own container/Neon
 * machinery, the same way RulesNeonTest loads rules.neon. This is the M3
 * defect class this rule exists to kill — layering.neon existing in the
 * package is worthless if `composer stan` at the repo root never includes
 * it and never registers LayeringRule. Container construction alone proves
 * the `includes:` + `atomsLayering.zones` wiring in phpstan.neon.dist is
 * well-formed; it does not run analysis.
 */
final class LayeringNeonTest extends PHPStanTestCase
{
    /**
     * @return string[]
     */
    public static function getAdditionalConfigFiles(): array
    {
        return [
            __DIR__ . '/../../../phpstan.neon.dist',
        ];
    }

    public function testLayeringRuleIsRegisteredByTheRootConfig(): void
    {
        $rules = self::getContainer()->getServicesByTag(LazyRegistry::RULE_TAG);
        $registeredClasses = array_map(static fn (object $rule): string => $rule::class, $rules);

        self::assertContains(
            LayeringRule::class,
            $registeredClasses,
            'LayeringRule should be registered once phpstan.neon.dist includes packages/phpstan-rules/layering.neon',
        );
    }

    public function testRootConfigDefinesZonesForCoreAndClient(): void
    {
        /** @var array{zones: list<array{paths: list<string>, forbid: list<string>, allow: list<string>}>} $atomsLayering */
        $atomsLayering = self::getContainer()->getParameter('atomsLayering');
        $zones = $atomsLayering['zones'];

        self::assertNotSame([], $zones, 'phpstan.neon.dist should define at least one atomsLayering zone');

        $allPaths = [];
        foreach ($zones as $zone) {
            foreach ($zone['paths'] as $path) {
                $allPaths[] = $path;
            }
        }

        self::assertContains('packages/core/src', $allPaths);
        self::assertContains('packages/client/src', $allPaths);
    }

    /**
     * Finding §2 regression guard: every path phpstan.neon.dist actually
     * analyses (`parameters.paths`) must fall under at least one
     * `atomsLayering` zone. Without this, adding an eighth analysed package
     * (or, as happened, leaving packages/phpstan-rules/src off the zone
     * list while it stayed on the paths list) analyses that package's code
     * with LayeringRule silently never running over it — an Illuminate or
     * Atoms\Client import there would pass with zero errors.
     */
    public function testEveryAnalysedPathIsCoveredByAnAtomsLayeringZone(): void
    {
        /** @var list<string> $paths */
        $paths = self::getContainer()->getParameter('paths');

        self::assertNotSame([], $paths, 'phpstan.neon.dist should define at least one analysed path');

        /** @var array{zones: list<array{paths: list<string>, forbid: list<string>, allow: list<string>}>} $atomsLayering */
        $atomsLayering = self::getContainer()->getParameter('atomsLayering');
        $zones = $atomsLayering['zones'];

        foreach ($paths as $path) {
            $coveredByAZone = false;
            foreach ($zones as $zone) {
                if (PathMatcher::isUnderAnyPath($path, $zone['paths'])) {
                    $coveredByAZone = true;
                    break;
                }
            }

            self::assertTrue(
                $coveredByAZone,
                sprintf(
                    'parameters.paths entry "%s" has no atomsLayering zone whose paths cover it — LayeringRule '
                        . 'never runs over that path even though composer stan analyses it. Add a zone for it in '
                        . 'phpstan.neon.dist.',
                    $path,
                ),
            );
        }
    }
}
