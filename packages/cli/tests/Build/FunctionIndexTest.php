<?php

declare(strict_types=1);

namespace Atoms\Cli\Tests\Build;

use Atoms\Cli\Build\ClosureWalker;
use Atoms\Cli\Build\DiscoveryResult;
use Atoms\Cli\Build\FunctionIndex;
use Atoms\Cli\Build\ReferenceCollector;
use Atoms\Cli\Build\SymbolClassifier;
use Atoms\Cli\Build\SymbolKind;
use Atoms\Cli\Build\Violation;
use Atoms\Cli\Config\AllowedPackages;
use Atoms\Cli\Tests\TestCase;
use Atoms\Errors\ErrorCode;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\DataProvider;

final class FunctionIndexTest extends TestCase
{
    public function testIndexesProjectNamespacedAndPolyfillFunctionsCaseInsensitively(): void
    {
        $index = new FunctionIndex();
        $index->addSource('<?php namespace App; function local() {}', 'app/A.php');
        $index->addSource("<?php namespace App; if (!function_exists('App\\\\polyfill')) { function polyfill() {} }", 'vendor/polyfill.php');

        self::assertTrue($index->has('app\\LOCAL'));
        self::assertTrue($index->has('\\App\\local'));
        self::assertTrue($index->has('App\\polyfill'));
        self::assertFalse($index->has('App\\missing'));
    }

    public function testOnlyEagerVendorAutoloadFilesAreIndexed(): void
    {
        $index = new FunctionIndex();
        $findings = $index->addVendorEntries([
            ['name' => 'vendor/p/functions.php', 'contents' => '<?php if (!function_exists("eager_helper")) { function eager_helper() {} }'],
            ['name' => 'vendor/p/unused.php', 'contents' => '<?php function unloaded_helper() {}'],
        ], ['vendor/p/functions.php']);

        self::assertSame([], $findings);
        self::assertTrue($index->has('eager_helper'));
        self::assertFalse($index->has('unloaded_helper'));
    }

    public function testFollowsLiteralDirIncludesFromEagerVendorFilesAndHandlesCycles(): void
    {
        $index = new FunctionIndex();
        $findings = $index->addVendorEntries([
            [
                'name' => 'vendor/symfony/polyfill-mbstring/bootstrap.php',
                'contents' => "<?php\nif (\\PHP_VERSION_ID >= 80000) {\n    return require __DIR__ . '/bootstrap80.php';\n}\nreturn require __DIR__ . '/bootstrap72.php';\n",
            ],
            [
                'name' => 'vendor/symfony/polyfill-mbstring/bootstrap80.php',
                'contents' => "<?php\nrequire_once __DIR__ . '/bootstrap.php';\nif (!function_exists('mb_trim')) {\n    function mb_trim(string \$string): string { return trim(\$string); }\n}\n",
            ],
            [
                'name' => 'vendor/symfony/polyfill-mbstring/unused.php',
                'contents' => '<?php function mb_unused(): void {}',
            ],
        ], ['vendor/symfony/polyfill-mbstring/bootstrap.php']);

        self::assertSame([], $findings);
        self::assertTrue($index->has('mb_trim'));
        self::assertFalse($index->has('mb_unused'));
    }

    public function testEagerVendorSyntaxFailuresAreReported(): void
    {
        $index = new FunctionIndex();
        $findings = $index->addVendorEntries([
            ['name' => 'vendor/p/invalid.php', 'contents' => '<?php function broken( {'],
            ['name' => 'vendor/p/not-loaded.php', 'contents' => '<?php function broken( {'],
        ], ['vendor/p/invalid.php']);

        self::assertCount(1, $findings);
        self::assertSame('vendor/p/invalid.php', $findings[0]->file);
        self::assertSame(ErrorCode::IncompatibleWorkerSyntax, $findings[0]->code);
    }

    public function testVersionSwitchingVendorBootstrapSkipsPhp84BranchAndIndexesPhp83Helper(): void
    {
        $index = new FunctionIndex();
        $findings = $index->addVendorEntries([
            [
                'name' => 'vendor/acme/polyfill/bootstrap.php',
                'contents' => <<<'PHP'
                    <?php
                    if (PHP_VERSION_ID >= 80400) {
                        require __DIR__ . '/php84.php';
                    } else {
                        require __DIR__ . '/php83.php';
                    }
                    PHP,
            ],
            [
                'name' => 'vendor/acme/polyfill/php84.php',
                'contents' => "<?php\nfinal class Hooked { public string \$label { get => 'php84'; } }\n",
            ],
            [
                'name' => 'vendor/acme/polyfill/php83.php',
                'contents' => "<?php\nif (!function_exists('acme_php83_helper')) {\n    function acme_php83_helper(): string { return 'php83'; }\n}\n",
            ],
        ], ['vendor/acme/polyfill/bootstrap.php']);

        self::assertSame([], $findings);
        self::assertTrue($index->has('acme_php83_helper'));
    }

    public function testEarlyReturnGuardedVendorIncludeSkipsUnparseableTarget(): void
    {
        $index = new FunctionIndex();
        $findings = $index->addVendorEntries([
            [
                'name' => 'vendor/acme/polyfill/bootstrap.php',
                'contents' => <<<'PHP'
                    <?php
                    if (PHP_VERSION_ID < 80400) {
                        return;
                    }
                    require __DIR__ . '/php84.php';
                    PHP,
            ],
            [
                'name' => 'vendor/acme/polyfill/php84.php',
                'contents' => "<?php\nfinal class Hooked { public string \$label { get => 'php84'; } }\n",
            ],
        ], ['vendor/acme/polyfill/bootstrap.php']);

        self::assertSame([], $findings);
    }

    /** @return iterable<string, array{string}> */
    public static function unconditionalForwardingBootstraps(): iterable
    {
        yield 'require' => ["<?php\nrequire __DIR__ . '/php84.php';\n"];
        yield 'return require_once' => [<<<'PHP'
            <?php
            declare(strict_types=1);
            namespace Acme\Polyfill;
            use stdClass;
            // forward to implementation
            return require_once __DIR__ . '/php84.php';
            PHP];
    }

    #[DataProvider('unconditionalForwardingBootstraps')]
    public function testUnconditionalForwardingVendorBootstrapReportsUnparseableTarget(string $bootstrap): void
    {
        $index = new FunctionIndex();
        $findings = $index->addVendorEntries([
            ['name' => 'vendor/acme/polyfill/bootstrap.php', 'contents' => $bootstrap],
            [
                'name' => 'vendor/acme/polyfill/php84.php',
                'contents' => "<?php\nfinal class Hooked { public string \$label { get => 'php84'; } }\n",
            ],
        ], ['vendor/acme/polyfill/bootstrap.php']);

        self::assertCount(1, $findings);
        self::assertSame('vendor/acme/polyfill/php84.php', $findings[0]->file);
        self::assertSame(ErrorCode::IncompatibleWorkerSyntax, $findings[0]->code);
    }

    public function testConditionalIncludeReCheckedWhenAlsoReachedAsMandatory(): void
    {
        $index = new FunctionIndex();
        $findings = $index->addVendorEntries([
            [
                'name' => 'vendor/acme/polyfill/conditional.php',
                'contents' => "<?php\nif (PHP_VERSION_ID >= 80400) {\n    require __DIR__ . '/php84.php';\n}\n",
            ],
            [
                'name' => 'vendor/acme/polyfill/eager.php',
                'contents' => "<?php\nrequire __DIR__ . '/hop.php';\n",
            ],
            [
                'name' => 'vendor/acme/polyfill/hop.php',
                'contents' => "<?php\nrequire __DIR__ . '/php84.php';\n",
            ],
            [
                'name' => 'vendor/acme/polyfill/php84.php',
                'contents' => "<?php\nfinal class Hooked { public string \$label { get => 'php84'; } }\n",
            ],
        ], ['vendor/acme/polyfill/conditional.php', 'vendor/acme/polyfill/eager.php']);

        self::assertCount(1, $findings);
        self::assertSame('vendor/acme/polyfill/php84.php', $findings[0]->file);
        self::assertSame(ErrorCode::IncompatibleWorkerSyntax, $findings[0]->code);
    }

    public function testTopLevelAndMigrationFunctionBodiesAreChecked(): void
    {
        $index = new FunctionIndex();
        $ast = $index->addSource('<?php namespace App; missing_top(); function migration() { missing_body(); }', 'migrations/001.php');
        self::assertNotInstanceOf(Violation::class, $ast);

        $discovery = new DiscoveryResult([], []);
        $classifier = new SymbolClassifier($discovery, AllowedPackages::load(), [], null, $index);
        $findings = (new ClosureWalker($discovery, $classifier, ['migrations/001.php' => $ast]))->walk();

        self::assertCount(2, $findings);
        self::assertSame(['missing_top', 'missing_body'], array_map(static fn ($finding) => $finding->symbol, $findings));
    }

    public function testNamedMigrationClassMethodsAreChecked(): void
    {
        $index = new FunctionIndex();
        $ast = $index->addSource('<?php class Migration { public function up() { missing_migration_function(); } }', 'migrations/001.php');
        self::assertNotInstanceOf(Violation::class, $ast);

        $discovery = new DiscoveryResult([], []);
        $classifier = new SymbolClassifier($discovery, AllowedPackages::load(), [], null, $index);
        $findings = (new ClosureWalker($discovery, $classifier, ['migrations/001.php' => $ast]))->walk();

        self::assertCount(1, $findings);
        self::assertSame('missing_migration_function', $findings[0]->symbol);
    }

    public function testNamespaceFallbackImportsAndUnknownFunctions(): void
    {
        $index = new FunctionIndex();
        $index->addSource('<?php namespace App; function strlen() {}', 'app/A.php');
        $classifier = new SymbolClassifier(new DiscoveryResult([], []), AllowedPackages::load(), [], null, $index);
        $source = <<<'PHP'
            <?php namespace App;
            use function array_map as map;
            function run() { strlen('x'); map(null, []); missing(); \Missing\qualified(); }
            PHP;
        $ast = (new ParserFactory())->createForNewestSupportedVersion()->parse($source) ?? [];
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver(null, ['preserveOriginalNames' => true]));
        $ast = $traverser->traverse($ast);
        $collector = new ReferenceCollector();
        $traverser = new NodeTraverser();
        $traverser->addVisitor($collector);
        $traverser->traverse($ast);
        $refs = array_values(array_filter($collector->references(), static fn (array $ref): bool => $ref['kind'] === SymbolKind::FunctionCall));
        self::assertCount(4, $refs);
        $findings = [];
        foreach ($refs as $ref) {
            $findings[] = $classifier->classify($ref['name'], $ref['kind'], 'app/A.php', $ref['line'], fallback: $ref['fallback'] ?? null);
        }
        self::assertNull($findings[0]);
        self::assertNull($findings[1]);
        self::assertSame(ErrorCode::UnavailableWorkerFunction, $findings[2]?->code);
        self::assertSame(ErrorCode::UnavailableWorkerFunction, $findings[3]?->code);
    }
}
