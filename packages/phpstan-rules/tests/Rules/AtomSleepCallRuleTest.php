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

        return new AtomSleepCallRule(new WorldClassifier($config));
    }

    public function testFlagsEverySleepFamilyCall(): void
    {
        $file = __DIR__ . '/../Fixtures/Clock/SleepAtom.php';

        $this->analyse([$file], [
            [ErrorCatalog::format(ErrorCode::SleepInAtom, [
                'symbol' => 'sleep',
                'class' => 'Atoms\PHPStan\Tests\Fixtures\Clock\SleepAtom',
            ]), 19],
            [ErrorCatalog::format(ErrorCode::SleepInAtom, [
                'symbol' => 'usleep',
                'class' => 'Atoms\PHPStan\Tests\Fixtures\Clock\SleepAtom',
            ]), 20],
            [ErrorCatalog::format(ErrorCode::SleepInAtom, [
                'symbol' => 'time_nanosleep',
                'class' => 'Atoms\PHPStan\Tests\Fixtures\Clock\SleepAtom',
            ]), 21],
            [ErrorCatalog::format(ErrorCode::SleepInAtom, [
                'symbol' => 'time_sleep_until',
                'class' => 'Atoms\PHPStan\Tests\Fixtures\Clock\SleepAtom',
            ]), 22],
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
        $file = __DIR__ . '/../Fixtures/Clock/SleepAtom.php';
        $errors = $this->gatherAnalyserErrors([$file]);

        self::assertNotEmpty($errors);
        foreach ($errors as $error) {
            self::assertMatchesRegularExpression('/ATOMS-E1\d\d/', $error->getMessage());
            self::assertDoesNotMatchRegularExpression('/ATOMS-E0\d\d/', $error->getMessage());
        }
    }
}
