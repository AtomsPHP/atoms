<?php

declare(strict_types=1);

namespace Atoms\PHPStan\Tests\Rules;

use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;
use Atoms\PHPStan\AtomsRulesConfig;
use Atoms\PHPStan\Rules\BoundarySignatureRule;
use Atoms\PHPStan\WorldClassifier;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<BoundarySignatureRule>
 */
final class BoundarySignatureRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        $config = new AtomsRulesConfig(
            atomsPaths: ['tests/Fixtures'],
            sharedPaths: ['tests/Fixtures/Shared'],
        );

        return new BoundarySignatureRule(new WorldClassifier($config), self::createReflectionProvider());
    }

    public function testCleanAtomHasNoViolations(): void
    {
        $this->analyse([__DIR__ . '/../Fixtures/Clean/CleanAtom.php'], []);
    }

    public function testFlagsIllegalBoundaryTypes(): void
    {
        $this->analyse([
            __DIR__ . '/../Fixtures/Signature/Note.php',
            __DIR__ . '/../Fixtures/Signature/SignatureAtom.php',
            __DIR__ . '/../Fixtures/Signature/SignatureMethods.php',
            __DIR__ . '/../Fixtures/Signature/SignatureJob.php',
        ], [
            [
                ErrorCatalog::format(ErrorCode::BoundaryTypeOutsideAlgebra, [
                    'location' => 'Atoms\PHPStan\Tests\Fixtures\Signature\SignatureAtom::badParam() parameter $when',
                    'type' => 'DateTime',
                ]),
                15,
            ],
            [
                ErrorCatalog::format(ErrorCode::BoundaryTypeOutsideAlgebra, [
                    'location' => 'Atoms\PHPStan\Tests\Fixtures\Signature\SignatureAtom::badReturn() return type',
                    'type' => 'App\Services\Calculator',
                ]),
                19,
            ],
            [
                ErrorCatalog::format(ErrorCode::OrmObjectAtBoundary, [
                    'location' => 'Atoms\PHPStan\Tests\Fixtures\Signature\SignatureAtom::ormParam() parameter $model',
                    'type' => 'Illuminate\Database\Eloquent\Model',
                ]),
                24,
            ],
            [
                ErrorCatalog::format(ErrorCode::OrmObjectAtBoundary, [
                    'location' => 'Atoms\PHPStan\Tests\Fixtures\Signature\SignatureAtom::doctrineParam() parameter $em',
                    'type' => 'Doctrine\ORM\EntityManagerInterface',
                ]),
                28,
            ],
            [
                ErrorCatalog::format(ErrorCode::BoundaryTypeOutsideAlgebra, [
                    'location' => 'Atoms\PHPStan\Tests\Fixtures\Signature\SignatureMethods::badCallback() parameter $when',
                    'type' => 'DateTime',
                ]),
                20,
            ],
            [
                ErrorCatalog::format(ErrorCode::OrmObjectAtBoundary, [
                    'location' => 'Atoms\PHPStan\Tests\Fixtures\Signature\SignatureJob::__construct() parameter $model',
                    'type' => 'Illuminate\Database\Eloquent\Model',
                ]),
                11,
            ],
        ]);
    }
}
