<?php

declare(strict_types=1);

namespace Atoms\PHPStan\Tests\Rules;

use Atoms\PHPStan\AtomsRulesConfig;
use Atoms\PHPStan\BoundaryReferenceInspector;
use Atoms\PHPStan\SideClassifier;

/**
 * Shared test wiring for the four class-reference rule tests
 * (BoundaryNewRule, BoundaryStaticCallRule, BoundaryClassConstRule,
 * BoundaryInstanceofRule): the same AtomsRulesConfig/SideClassifier/
 * BoundaryReferenceInspector trio, built by hand (no DI container) against
 * the fixture layout under tests/Fixtures.
 */
trait BoundaryReferenceRuleTestTrait
{
    private function makeInspector(): BoundaryReferenceInspector
    {
        $config = new AtomsRulesConfig(
            atomsPaths: ['tests/Fixtures'],
            sharedPaths: ['tests/Fixtures/Shared'],
        );
        $classifier = new SideClassifier($config);

        return new BoundaryReferenceInspector($config, $classifier, self::createReflectionProvider());
    }

    private function makeClassifier(): SideClassifier
    {
        $config = new AtomsRulesConfig(
            atomsPaths: ['tests/Fixtures'],
            sharedPaths: ['tests/Fixtures/Shared'],
        );

        return new SideClassifier($config);
    }
}
