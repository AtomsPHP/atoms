<?php

declare(strict_types=1);

namespace Atoms\PHPStan\Tests\Rules;

use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;
use Atoms\PHPStan\Rules\BoundaryStaticCallRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<BoundaryStaticCallRule>
 */
final class BoundaryStaticCallRuleTest extends RuleTestCase
{
    use BoundaryReferenceRuleTestTrait;

    protected function getRule(): Rule
    {
        return new BoundaryStaticCallRule($this->makeClassifier(), $this->makeInspector());
    }

    public function testCleanAtomHasNoViolations(): void
    {
        $this->analyse([__DIR__ . '/../Fixtures/Clean/CleanAtom.php'], []);
    }

    public function testFlagsIllegalStaticCalls(): void
    {
        $file = __DIR__ . '/../Fixtures/Reference/ReferenceAtom.php';

        $this->analyse([$file], [
            [
                ErrorCatalog::format(ErrorCode::FrameworkSymbolInAtom, ['symbol' => 'Illuminate\Support\Str']),
                20,
            ],
            [
                ErrorCatalog::format(ErrorCode::FacadeInAtom, ['symbol' => 'Illuminate\Support\Facades\Auth']),
                21,
            ],
        ]);
    }
}
