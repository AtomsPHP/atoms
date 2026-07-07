<?php

declare(strict_types=1);

namespace Atoms\PHPStan\Tests\Rules;

use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;
use Atoms\PHPStan\AtomsRulesConfig;
use Atoms\PHPStan\Rules\BoundaryFunctionCallRule;
use Atoms\PHPStan\WorldClassifier;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<BoundaryFunctionCallRule>
 */
final class BoundaryFunctionCallRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        $config = new AtomsRulesConfig(
            atomsPaths: ['tests/Fixtures'],
            sharedPaths: ['tests/Fixtures/Shared'],
        );

        return new BoundaryFunctionCallRule(new WorldClassifier($config));
    }

    public function testCleanAtomHasNoViolations(): void
    {
        $this->analyse([__DIR__ . '/../Fixtures/Clean/CleanAtom.php'], []);
    }

    public function testFlagsEnvSerializeAndHelperCalls(): void
    {
        $file = __DIR__ . '/../Fixtures/FuncCall/FuncCallAtom.php';

        $this->analyse([$file], [
            [ErrorCatalog::format(ErrorCode::EnvInAtom, ['symbol' => 'env']), 13],
            [ErrorCatalog::format(ErrorCode::NativeSerializationAtBoundary), 14],
            [ErrorCatalog::format(ErrorCode::NativeSerializationAtBoundary), 15],
            [ErrorCatalog::format(ErrorCode::FrameworkHelperInAtom, ['symbol' => 'config']), 16],
            [ErrorCatalog::format(ErrorCode::FrameworkHelperInAtom, ['symbol' => 'now']), 17],
        ]);
    }

    public function testMessagesCarryTheAtomsErrorCode(): void
    {
        $file = __DIR__ . '/../Fixtures/FuncCall/FuncCallAtom.php';
        $errors = $this->gatherAnalyserErrors([$file]);

        self::assertNotEmpty($errors);
        foreach ($errors as $error) {
            self::assertMatchesRegularExpression('/ATOMS-E0\d\d/', $error->getMessage());
        }
    }
}
