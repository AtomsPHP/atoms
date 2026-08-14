<?php

declare(strict_types=1);

namespace Atoms\PHPStan\Tests\Rules;

use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;
use Atoms\PHPStan\AtomsRulesConfig;
use Atoms\PHPStan\Rules\AtomTimeWaitLoopRule;
use Atoms\PHPStan\WorldClassifier;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<AtomTimeWaitLoopRule>
 */
final class AtomTimeWaitLoopRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        $config = new AtomsRulesConfig(
            atomsPaths: ['tests/Fixtures'],
            sharedPaths: ['tests/Fixtures/Shared'],
        );

        return new AtomTimeWaitLoopRule(new WorldClassifier($config), self::createReflectionProvider());
    }

    public function testFlagsEveryWaitLoopShape(): void
    {
        $file = __DIR__ . '/../Fixtures/Clock/WaitLoopAtom.php';
        $class = 'Atoms\PHPStan\Tests\Fixtures\Clock\WaitLoopAtom';

        $this->analyse([$file], [
            [ErrorCatalog::format(ErrorCode::TimeWaitLoopInAtom, [
                'class' => $class,
                'symbol' => 'time',
            ]), 21],
            [ErrorCatalog::format(ErrorCode::TimeWaitLoopInAtom, [
                'class' => $class,
                'symbol' => 'microtime',
            ]), 28],
            [ErrorCatalog::format(ErrorCode::TimeWaitLoopInAtom, [
                'class' => $class,
                'symbol' => 'hrtime',
            ]), 35],
            [ErrorCatalog::format(ErrorCode::TimeWaitLoopInAtom, [
                'class' => $class,
                'symbol' => 'time',
            ]), 42],
            [ErrorCatalog::format(ErrorCode::TimeWaitLoopInAtom, [
                'class' => $class,
                'symbol' => 'time',
            ]), 51],
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
     * A WORLD_A namespace that defines its own time()/microtime()/hrtime()/
     * gettimeofday() functions and its own DateTimeImmutable class, and reads
     * each unqualified in a wait-loop condition, must produce zero errors:
     * unqualified function calls resolve to the namespace-local function
     * first, and unqualified class names resolve to the namespace-local
     * class at compile time — neither ever reaches the dangerous global.
     *
     * The require_once is deliberate: PHPStan's own parser/AST reflection
     * never executes the fixture, so without this, PHP's runtime has no
     * declaration of the namespace-local time() et al. for BetterReflection
     * to bridge to via ReflectionFunction — see the fixture's docblock.
     */
    public function testShadowedClockReadsHaveNoViolations(): void
    {
        require_once __DIR__ . '/../Fixtures/Clock/Shadow/ShadowedWaitLoopAtom.php';

        $this->analyse([__DIR__ . '/../Fixtures/Clock/Shadow/ShadowedWaitLoopAtom.php'], []);
    }

    public function testMessagesCarryTheAtomsErrorCode(): void
    {
        $file = __DIR__ . '/../Fixtures/Clock/WaitLoopAtom.php';
        $errors = $this->gatherAnalyserErrors([$file]);

        self::assertNotEmpty($errors);
        foreach ($errors as $error) {
            self::assertMatchesRegularExpression('/ATOMS-E1\d\d/', $error->getMessage());
            self::assertDoesNotMatchRegularExpression('/ATOMS-E0\d\d/', $error->getMessage());
        }
    }
}
