<?php

declare(strict_types=1);

namespace Atoms\PHPStan\Tests\Rules;

use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;
use Atoms\PHPStan\AtomsLayeringConfig;
use Atoms\PHPStan\Rules\LayeringRule;
use PHPStan\File\SimpleRelativePathHelper;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<LayeringRule>
 */
final class LayeringRuleTest extends RuleTestCase
{
    private const RELATIVE_FILE = 'tests/Fixtures/Layering/ForbiddenZone/UsesFramework.php';

    protected function getRule(): Rule
    {
        $config = new AtomsLayeringConfig(
            zones: [
                [
                    'paths' => ['tests/Fixtures/Layering/ForbiddenZone'],
                    'forbid' => ['Illuminate', 'Laravel', 'Symfony', 'Doctrine', 'GuzzleHttp'],
                    'allow' => [],
                ],
            ],
            forbiddenFunctions: ['config', 'app', 'resolve', 'env', 'now'],
        );

        // Package-root-relative, deterministic paths in messages regardless
        // of where the repo checkout lives — mirrors how AtomsRulesConfig's
        // own tests configure paths relative to tests/Fixtures.
        return new LayeringRule($config, new SimpleRelativePathHelper(dirname(__DIR__, 2)));
    }

    public function testCleanClientHasNoViolations(): void
    {
        $this->analyse([__DIR__ . '/../Fixtures/Layering/ForbiddenZone/CleanClient.php'], []);
    }

    /**
     * The regression guard this fixture exists for: a docblock containing
     * the prose "Laravel/Symfony" (forward slash) must never be mistaken
     * for a namespaced reference to Laravel\... or Symfony\... (backslash).
     */
    public function testCleanClientDocCommentProseIsNotMistakenForANamespace(): void
    {
        $errors = $this->gatherAnalyserErrors([__DIR__ . '/../Fixtures/Layering/ForbiddenZone/CleanClient.php']);

        self::assertSame([], $errors);
    }

    public function testFileOutsideAnyZonePathHasNoViolations(): void
    {
        // Byte-similar to ForbiddenZone/UsesFramework.php, but this test's
        // zone only covers the ForbiddenZone directory — proving the
        // violation is about zone path scoping, not about the file content.
        $this->analyse([__DIR__ . '/../Fixtures/Layering/AllowedZone/UsesFramework.php'], []);
    }

    public function testFlagsEveryConstructInUsesFramework(): void
    {
        $file = __DIR__ . '/../Fixtures/Layering/ForbiddenZone/UsesFramework.php';

        $this->analyse([$file], [
            $this->expected('Illuminate\Support\Str', 7),
            $this->expected('Illuminate\Support\Arr', 8),
            $this->expected('Illuminate\Support\Collection', 8),
            $this->expected('Illuminate\Support\str_slug', 9),
            $this->expected('Symfony\Component\Routing\Annotation\Route', 11),
            $this->expected('Illuminate\Contracts\Support\Arrayable', 12),
            $this->expected('Illuminate\Support\Traits\Macroable', 14),
            $this->expected('Doctrine\ORM\EntityManagerInterface', 16),
            $this->expected('GuzzleHttp\Client', 18),
            $this->expected('Illuminate\Support\Collection', 18),
            $this->expected('Illuminate\Support\Collection', 20),
            $this->expected('Illuminate\Support\Str', 22),
            $this->expected('Illuminate\Http\Response', 24),
            $this->expected('Illuminate\Support\Collection', 26),
            $this->expected('Illuminate\Support\Collection', 28),
            $this->expected('Illuminate\Http\Client\ConnectionException', 32),
            $this->expected('config', 35),
            $this->expected('Illuminate\Http\Request', 37),
            $this->expected('Illuminate\Support\Collection', 39),
            $this->expected('config', 42),
            $this->expected('app', 44),
        ]);
    }

    public function testFlagsExactlyTwentyOneViolations(): void
    {
        $errors = $this->gatherAnalyserErrors([__DIR__ . '/../Fixtures/Layering/ForbiddenZone/UsesFramework.php']);

        self::assertCount(21, $errors);
    }

    /**
     * A fully-qualified call to a plain built-in (`\strlen(...)`, not a
     * framework helper and not a namespaced symbol) must never be flagged —
     * see CleanClient.php.
     */
    public function testFullyQualifiedBuiltinCallIsNotAFalsePositive(): void
    {
        $errors = $this->gatherAnalyserErrors([__DIR__ . '/../Fixtures/Layering/ForbiddenZone/CleanClient.php']);

        self::assertSame([], $errors);
    }

    public function testMessagesCarryTheAtomsErrorCode(): void
    {
        $file = __DIR__ . '/../Fixtures/Layering/ForbiddenZone/UsesFramework.php';
        $errors = $this->gatherAnalyserErrors([$file]);

        self::assertNotEmpty($errors);
        foreach ($errors as $error) {
            self::assertStringContainsString('ATOMS-E100', $error->getMessage());
        }
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function expected(string $symbol, int $line): array
    {
        return [
            ErrorCatalog::format(ErrorCode::LayeringViolation, [
                'symbol' => $symbol,
                'file' => self::RELATIVE_FILE,
            ]),
            $line,
        ];
    }
}
