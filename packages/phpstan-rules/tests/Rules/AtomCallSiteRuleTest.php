<?php

declare(strict_types=1);

namespace Atoms\PHPStan\Tests\Rules;

use Atoms\PHPStan\Rules\AtomCallSiteRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<AtomCallSiteRule>
 */
final class AtomCallSiteRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new AtomCallSiteRule(self::createReflectionProvider());
    }

    public function testCleanAtomHasNoViolations(): void
    {
        $this->analyse([__DIR__ . '/../Fixtures/Clean/CleanAtom.php'], []);
    }

    /**
     * @return list<string>
     */
    private function fixtureFiles(): array
    {
        return [
            __DIR__ . '/../Fixtures/CallSite/GreeterAtom.php',
            __DIR__ . '/../Fixtures/CallSite/FakeAtomsClient.php',
            __DIR__ . '/../Fixtures/CallSite/CallSiteUsage.php',
        ];
    }

    public function testValidCallSiteHasNoViolation(): void
    {
        $errors = $this->gatherAnalyserErrors($this->fixtureFiles());

        $lines = array_map(static fn ($error) => $error->getLine(), $errors);

        self::assertNotContains(11, $lines, 'the well-formed call site at line 11 must not be flagged');
    }

    public function testFlagsArityMismatch(): void
    {
        $errors = $this->gatherAnalyserErrors($this->fixtureFiles());

        $byLine = [];
        foreach ($errors as $error) {
            $byLine[$error->getLine()] = $error->getMessage();
        }

        self::assertArrayHasKey(16, $byLine);
        self::assertStringContainsString('ATOMS-E041', $byLine[16]);
        self::assertStringContainsString('argument', $byLine[16]);
    }

    public function testFlagsUnknownMethod(): void
    {
        $errors = $this->gatherAnalyserErrors($this->fixtureFiles());

        $byLine = [];
        foreach ($errors as $error) {
            $byLine[$error->getLine()] = $error->getMessage();
        }

        self::assertArrayHasKey(21, $byLine);
        self::assertStringContainsString('ATOMS-E041', $byLine[21]);
        self::assertStringContainsString('doesNotExist', $byLine[21]);
    }

    public function testFlagsNonPublicMethod(): void
    {
        $errors = $this->gatherAnalyserErrors($this->fixtureFiles());

        $byLine = [];
        foreach ($errors as $error) {
            $byLine[$error->getLine()] = $error->getMessage();
        }

        self::assertArrayHasKey(26, $byLine);
        self::assertStringContainsString('ATOMS-E041', $byLine[26]);
        self::assertStringContainsString('not public', $byLine[26]);
    }

    public function testFlagsExactlyThreeViolations(): void
    {
        $errors = $this->gatherAnalyserErrors($this->fixtureFiles());

        self::assertCount(3, $errors);
    }
}
