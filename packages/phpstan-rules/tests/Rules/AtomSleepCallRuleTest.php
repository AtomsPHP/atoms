<?php

declare(strict_types=1);

namespace Atoms\PHPStan\Tests\Rules;

use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;
use Atoms\PHPStan\AtomsRulesConfig;
use Atoms\PHPStan\Rules\AtomSleepCallRule;
use Atoms\PHPStan\WorldClassifier;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<AtomSleepCallRule>
 */
final class AtomSleepCallRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        $config = new AtomsRulesConfig(
            atomsPaths: ['tests/Fixtures'],
            sharedPaths: ['tests/Fixtures/Shared'],
        );

        return new AtomSleepCallRule(new WorldClassifier($config), self::createReflectionProvider());
    }

    public function testFlagsEverySleepFamilyCall(): void
    {
        $file = __DIR__ . '/../Fixtures/Clock/SleepAtom.php';

        $this->analyse([$file], [
            [ErrorCatalog::format(ErrorCode::SleepInAtom, [
                'symbol' => 'sleep',
                'class' => 'Atoms\PHPStan\Tests\Fixtures\Clock\SleepAtom',
            ]), 23],
            [ErrorCatalog::format(ErrorCode::SleepInAtom, [
                'symbol' => 'usleep',
                'class' => 'Atoms\PHPStan\Tests\Fixtures\Clock\SleepAtom',
            ]), 24],
            [ErrorCatalog::format(ErrorCode::SleepInAtom, [
                'symbol' => 'time_nanosleep',
                'class' => 'Atoms\PHPStan\Tests\Fixtures\Clock\SleepAtom',
            ]), 25],
            [ErrorCatalog::format(ErrorCode::SleepInAtom, [
                'symbol' => 'time_sleep_until',
                'class' => 'Atoms\PHPStan\Tests\Fixtures\Clock\SleepAtom',
            ]), 26],
            [ErrorCatalog::format(ErrorCode::SleepInAtom, [
                'symbol' => 'sleep',
                'class' => 'Atoms\PHPStan\Tests\Fixtures\Clock\SleepAtom',
            ]), 31],
        ]);
    }

    public function testCleanClockAtomHasNoViolations(): void
    {
        $this->analyse([__DIR__ . '/../Fixtures/Clock/CleanClockAtom.php'], []);
    }

    public function testWorldBSleepMethodsHasNoViolations(): void
    {
        $this->analyse([__DIR__ . '/../Fixtures/Clock/SleepMethods.php'], []);
    }

    /**
     * A WORLD_A namespace that defines its own sleep()/usleep()/
     * time_nanosleep()/time_sleep_until() and calls each unqualified must
     * produce zero errors: PHP resolves the unqualified call to the
     * namespace-local function first, never reaching the dangerous global.
     *
     * The require_once is deliberate: PHPStan's own parser/AST reflection
     * never executes the fixture, so without this, PHP's runtime has no
     * declaration of the namespace-local sleep() et al. for BetterReflection
     * to bridge to via ReflectionFunction — see the fixture's docblock.
     */
    public function testShadowedSleepFamilyHasNoViolations(): void
    {
        require_once __DIR__ . '/../Fixtures/Clock/Shadow/ShadowedSleepAtom.php';

        $this->analyse([__DIR__ . '/../Fixtures/Clock/Shadow/ShadowedSleepAtom.php'], []);
    }

    public function testMessagesCarryTheAtomsErrorCode(): void
    {
        $file = __DIR__ . '/../Fixtures/Clock/SleepAtom.php';
        $errors = $this->gatherAnalyserErrors([$file]);

        self::assertNotEmpty($errors);
        foreach ($errors as $error) {
            self::assertMatchesRegularExpression('/ATOMS-E1\d\d/', $error->getMessage());
            self::assertDoesNotMatchRegularExpression('/ATOMS-E0\d\d/', $error->getMessage());
        }
    }
}
