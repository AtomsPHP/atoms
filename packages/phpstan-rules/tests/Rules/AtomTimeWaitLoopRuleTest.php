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

        return new AtomTimeWaitLoopRule(new WorldClassifier($config));
    }

    public function testFlagsEveryWaitLoopShape(): void
    {
        $file = __DIR__ . '/../Fixtures/Clock/WaitLoopAtom.php';
        $class = 'Atoms\PHPStan\Tests\Fixtures\Clock\WaitLoopAtom';

        $this->analyse([$file], [
            [ErrorCatalog::format(ErrorCode::TimeWaitLoopInAtom, [
                'class' => $class,
                'symbol' => 'time',
            ]), 17],
            [ErrorCatalog::format(ErrorCode::TimeWaitLoopInAtom, [
                'class' => $class,
                'symbol' => 'microtime',
            ]), 24],
            [ErrorCatalog::format(ErrorCode::TimeWaitLoopInAtom, [
                'class' => $class,
                'symbol' => 'hrtime',
            ]), 31],
            [ErrorCatalog::format(ErrorCode::TimeWaitLoopInAtom, [
                'class' => $class,
                'symbol' => 'time',
            ]), 38],
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
