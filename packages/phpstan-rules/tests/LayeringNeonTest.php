<?php

declare(strict_types=1);

namespace Atoms\PHPStan\Tests;

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
}
