<?php

declare(strict_types=1);

namespace Atoms\PHPStan\Tests\Rules;

use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;
use Atoms\PHPStan\Rules\BoundaryClassConstRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<BoundaryClassConstRule>
 */
final class BoundaryClassConstRuleTest extends RuleTestCase
{
    use BoundaryReferenceRuleTestTrait;

    protected function getRule(): Rule
    {
        return new BoundaryClassConstRule($this->makeClassifier(), $this->makeInspector());
    }

    public function testCleanAtomHasNoViolations(): void
    {
        $this->analyse([__DIR__ . '/../Fixtures/Clean/CleanAtom.php'], []);
    }

    public function testFlagsIllegalClassConstFetch(): void
    {
        $file = __DIR__ . '/../Fixtures/Reference/ReferenceAtom.php';

        $this->analyse([$file], [
            [
                ErrorCatalog::format(ErrorCode::MonolithClassInAtom, ['symbol' => 'App\Models\User']),
                26,
            ],
        ]);
    }
}
