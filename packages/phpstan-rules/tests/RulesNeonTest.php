<?php

declare(strict_types=1);

namespace Atoms\PHPStan\Tests;

use Atoms\PHPStan\AtomsRulesConfig;
use Atoms\PHPStan\BoundaryReferenceInspector;
use Atoms\PHPStan\Rules\AtomCallSiteRule;
use Atoms\PHPStan\Rules\AtomSleepCallRule;
use Atoms\PHPStan\Rules\AtomTimeWaitLoopRule;
use Atoms\PHPStan\Rules\BoundaryClassConstRule;
use Atoms\PHPStan\Rules\BoundaryFunctionCallRule;
use Atoms\PHPStan\Rules\BoundaryInstanceofRule;
use Atoms\PHPStan\Rules\BoundaryNewRule;
use Atoms\PHPStan\Rules\BoundarySignatureRule;
use Atoms\PHPStan\Rules\BoundaryStaticCallRule;
use Atoms\PHPStan\Rules\PayloadHydratabilityRule;
use Atoms\PHPStan\WorldClassifier;
use PHPStan\Rules\LazyRegistry;
use PHPStan\Testing\PHPStanTestCase;

/**
 * Loads rules.neon through PHPStan's own container/Neon machinery — the
 * pragmatic stand-in for a bare Nette\Neon parse (that package isn't
 * separately autoloadable in this vendor tree; PHPStan's ContainerFactory
 * bundles its own copy internally). If the neon were malformed, or a
 * service/argument name didn't match, container construction below would
 * throw and fail every test in this class.
 */
final class RulesNeonTest extends PHPStanTestCase
{
    /**
     * @return string[]
     */
    public static function getAdditionalConfigFiles(): array
    {
        return [
            __DIR__ . '/../rules.neon',
        ];
    }

    public function testDefaultParametersWireIntoAtomsRulesConfig(): void
    {
        $config = self::getContainer()->getByType(AtomsRulesConfig::class);

        self::assertSame(['app/Atoms'], $config->atomsPaths());
        self::assertSame(['app/Atoms/Shared'], $config->sharedPaths());
        self::assertSame([], $config->allowedNamespaces());
    }

    public function testCollaboratorServicesResolve(): void
    {
        self::assertInstanceOf(WorldClassifier::class, self::getContainer()->getByType(WorldClassifier::class));
        self::assertInstanceOf(
            BoundaryReferenceInspector::class,
            self::getContainer()->getByType(BoundaryReferenceInspector::class),
        );
    }

    public function testAllTenRulesAreRegistered(): void
    {
        $rules = self::getContainer()->getServicesByTag(LazyRegistry::RULE_TAG);
        $registeredClasses = array_map(static fn (object $rule): string => $rule::class, $rules);

        foreach ([
            BoundaryFunctionCallRule::class,
            BoundaryNewRule::class,
            BoundaryStaticCallRule::class,
            BoundaryClassConstRule::class,
            BoundaryInstanceofRule::class,
            BoundarySignatureRule::class,
            PayloadHydratabilityRule::class,
            AtomCallSiteRule::class,
            AtomSleepCallRule::class,
            AtomTimeWaitLoopRule::class,
        ] as $expectedClass) {
            self::assertContains(
                $expectedClass,
                $registeredClasses,
                sprintf('%s should be registered under rules.neon\'s "rules:" section', $expectedClass),
            );
        }
    }
}
