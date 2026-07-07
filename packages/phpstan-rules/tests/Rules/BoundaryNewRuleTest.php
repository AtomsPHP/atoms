<?php

declare(strict_types=1);

namespace Atoms\PHPStan\Tests\Rules;

use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;
use Atoms\PHPStan\Rules\BoundaryNewRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<BoundaryNewRule>
 */
final class BoundaryNewRuleTest extends RuleTestCase
{
    use BoundaryReferenceRuleTestTrait;

    protected function getRule(): Rule
    {
        return new BoundaryNewRule($this->makeClassifier(), $this->makeInspector());
    }

    public function testCleanAtomHasNoViolations(): void
    {
        $this->analyse([__DIR__ . '/../Fixtures/Clean/CleanAtom.php'], []);
    }

    public function testFlagsIllegalNewInstantiations(): void
    {
        $this->analyse([
            __DIR__ . '/../Fixtures/Reference/ReferenceAtom.php',
            __DIR__ . '/../Fixtures/Reference/BadSharedDto.php',
        ], [
            [
                ErrorCatalog::format(ErrorCode::FrameworkSymbolInAtom, ['symbol' => 'Illuminate\Support\Collection']),
                14,
            ],
            [
                ErrorCatalog::format(ErrorCode::MonolithClassInAtom, ['symbol' => 'App\Models\User']),
                15,
            ],
            [
                ErrorCatalog::format(ErrorCode::SharedNonCoreSymbol, [
                    'class' => 'Atoms\PHPStan\Tests\Fixtures\Reference\BadSharedDto',
                    'symbol' => 'App\Models\User',
                ]),
                17,
            ],
        ]);
    }
}
