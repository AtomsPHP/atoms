<?php

declare(strict_types=1);

namespace Atoms\PHPStan\Tests\Rules;

use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;
use Atoms\PHPStan\AtomsRulesConfig;
use Atoms\PHPStan\Rules\AtomJobConstructionRule;
use Atoms\PHPStan\SideClassifier;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<AtomJobConstructionRule>
 */
final class AtomJobConstructionRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        $config = new AtomsRulesConfig(
            atomsPaths: ['tests/Fixtures'],
            sharedPaths: ['tests/Fixtures/Shared'],
        );

        return new AtomJobConstructionRule(new SideClassifier($config), self::createReflectionProvider());
    }

    public function testFlagsOnlyTheConstructedDispatch(): void
    {
        $file = __DIR__ . '/../Fixtures/Dispatch/DispatchAtom.php';

        // Exactly one error: not the by-name call, not the non-job.
        $this->analyse([$file], [
            [ErrorCatalog::format(ErrorCode::AtomJobConstructedInAtom, [
                'atom' => 'Atoms\PHPStan\Tests\Fixtures\Dispatch\DispatchAtom',
                'job' => 'Atoms\PHPStan\Tests\Fixtures\Clean\RecordResult',
            ]), 23],
        ]);
    }

    public function testAppSideMayConstructJobsFreely(): void
    {
        $this->analyse([__DIR__ . '/../Fixtures/Dispatch/Methods.php'], []);
    }
}
