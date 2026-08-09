<?php

declare(strict_types=1);

namespace Atoms\PHPStan\Tests\Rules;

use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;
use Atoms\PHPStan\Rules\PayloadHydratabilityRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<PayloadHydratabilityRule>
 */
final class PayloadHydratabilityRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new PayloadHydratabilityRule();
    }

    public function testCleanPayloadFixturesHaveNoViolations(): void
    {
        $this->analyse([
            __DIR__ . '/../Fixtures/Clean/PlayerSnapshot.php',
            __DIR__ . '/../Fixtures/Payload/GoodPayload.php',
        ], []);
    }

    public function testFlagsExtraDeclaredProperty(): void
    {
        $file = __DIR__ . '/../Fixtures/Payload/BadPayloadExtraProperty.php';

        $this->analyse([$file], [
            [
                ErrorCatalog::format(ErrorCode::PayloadNotHydratable, [
                    'class' => 'Atoms\PHPStan\Tests\Fixtures\Payload\BadPayloadExtraProperty',
                ]),
                9,
            ],
        ]);
    }

    public function testFlagsNonPromotedConstructorParam(): void
    {
        $file = __DIR__ . '/../Fixtures/Payload/BadPayloadNonPromotedParam.php';

        $this->analyse([$file], [
            [
                ErrorCatalog::format(ErrorCode::PayloadNotHydratable, [
                    'class' => 'Atoms\PHPStan\Tests\Fixtures\Payload\BadPayloadNonPromotedParam',
                ]),
                9,
            ],
        ]);
    }
}
