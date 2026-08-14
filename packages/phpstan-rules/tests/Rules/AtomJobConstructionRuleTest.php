<?php

declare(strict_types=1);

namespace Atoms\PHPStan\Tests\Rules;

use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;
use Atoms\PHPStan\AtomsRulesConfig;
use Atoms\PHPStan\Rules\AtomJobConstructionRule;
use Atoms\PHPStan\WorldClassifier;
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

        return new AtomJobConstructionRule(new WorldClassifier($config), self::createReflectionProvider());
    }

    public function testFlagsOnlyTheConstructedDispatch(): void
    {
        $file = __DIR__ . '/../Fixtures/Dispatch/DispatchAtom.php';

        // dispatch() on line 19 is legal and NotAJob on line 34 belongs to
        // the boundary rules — exactly one error, on the `new` job.
        $this->analyse([$file], [
            [ErrorCatalog::format(ErrorCode::AtomJobConstructedInAtom, [
                'atom' => 'Atoms\PHPStan\Tests\Fixtures\Dispatch\DispatchAtom',
                'job' => 'Atoms\PHPStan\Tests\Fixtures\Clean\RecordResult',
            ]), 27],
        ]);
    }

    public function testWorldBMayConstructJobsFreely(): void
    {
        // A Methods class is World B: it runs in the monolith, where the job
        // class is loaded like any other.
        $this->analyse([__DIR__ . '/../Fixtures/Dispatch/Methods.php'], []);
    }
}
