<?php

declare(strict_types=1);

namespace Atoms\Cli\Tests\Build;

use Atoms\Cli\Build\ClosureWalker;
use Atoms\Cli\Build\DiscoveryResult;
use Atoms\Cli\Build\FunctionIndex;
use Atoms\Cli\Build\ReferenceCollector;
use Atoms\Cli\Build\SymbolClassifier;
use Atoms\Cli\Build\SymbolKind;
use Atoms\Cli\Config\AllowedPackages;
use Atoms\Cli\Tests\TestCase;
use Atoms\Errors\ErrorCode;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

final class FunctionIndexTest extends TestCase
{
    public function testOnlyLoadedDeclarationsProvideFunctions(): void
    {
        $index = new FunctionIndex();
        $index->addSource('<?php namespace App; function local() {}', 'app/A.php');
        $index->addSource('<?php namespace App; function other() {}', 'app/B.php');
        $index->addSource("<?php namespace App; if (!function_exists('App\\\\polyfill')) { function polyfill() {} }", 'vendor/polyfill.php', true);
        self::assertTrue($index->has('app\\LOCAL', 'app/A.php'));
        self::assertFalse($index->has('App\\other', 'app/A.php'));
        self::assertTrue($index->has('App\\polyfill', 'app/A.php'));
    }

    public function testVendorLiteralIncludeIsEagerButOtherFilesAreNot(): void
    {
        $index = new FunctionIndex();
        $index->addVendorEntries([
            ['name' => 'vendor/p/start.php', 'contents' => "<?php require __DIR__ . '/src/functions.php';"],
            ['name' => 'vendor/p/src/functions.php', 'contents' => '<?php function eager_helper() {}'],
            ['name' => 'vendor/p/unused.php', 'contents' => '<?php function unloaded_helper() {}'],
        ], ['vendor/p/start.php']);
        self::assertTrue($index->has('eager_helper', 'app/A.php'));
        self::assertFalse($index->has('unloaded_helper', 'app/A.php'));
    }

    public function testGuardedDeclarationsRespectGuestVersionAndEarlyReturn(): void
    {
        $index = new FunctionIndex();
        $index->addSource(<<<'PHP'
            <?php
            if (PHP_VERSION_ID >= 80400) { return; }
            if (!function_exists('polyfill_83')) { function polyfill_83() {} }
            PHP, 'vendor/old.php', true);
        $index->addSource(<<<'PHP'
            <?php
            return;
            if (!function_exists('unreachable')) { function unreachable() {} }
            PHP, 'vendor/stopped.php', true);
        $index->addSource(<<<'PHP'
            <?php
            if (PHP_VERSION_ID >= 80400) { function future_only() {} }
            PHP, 'vendor/future.php', true);
        self::assertTrue($index->has('polyfill_83', 'app/A.php'));
        self::assertFalse($index->has('unreachable', 'app/A.php'));
        self::assertFalse($index->has('future_only', 'app/A.php'));
        $index->addSource(<<<'PHP'
            <?php
            if (PHP_VERSION_ID >= 80400) {} else { return; }
            if (!function_exists('after_else')) { function after_else() {} }
            function compiled_after_return() {}
            PHP, 'vendor/else.php', true);
        self::assertFalse($index->has('after_else', 'app/A.php'));
        self::assertTrue($index->has('compiled_after_return', 'app/A.php'));
    }

    public function testGuardOrderAndReturnsAcrossNamespaces(): void
    {
        $index = new FunctionIndex();
        $index->addSource(<<<'PHP'
            <?php
            namespace A {
                if (!function_exists('A\\before')) { function before() {} return; }
            }
            namespace B {
                if (!function_exists('B\\after')) { function after() {} }
                function compiled() {}
            }
            PHP, 'app/namespaces.php');
        self::assertTrue($index->has('A\\before', 'app/namespaces.php'));
        self::assertFalse($index->has('B\\after', 'app/namespaces.php'));
        self::assertTrue($index->has('B\\compiled', 'app/namespaces.php'));

        $index->addSource(<<<'PHP'
            <?php
            if (!function_exists('too_late')) { return; function too_late() {} }
            if (PHP_VERSION_ID >= 80400) {} elseif (PHP_VERSION_ID >= 80300) { return; }
            if (!function_exists('after_elseif')) { function after_elseif() {} }
            PHP, 'app/returns.php');
        self::assertFalse($index->has('too_late', 'app/returns.php'));
        self::assertFalse($index->has('after_elseif', 'app/returns.php'));
    }

    public function testProjectIncludesAreVisibleOnlyFromTheirLoadingChain(): void
    {
        $index = new FunctionIndex();
        $index->addSource("<?php require __DIR__ . '/support/one.php';", 'app/A.php');
        $index->addSource("<?php require __DIR__ . '/../two.php';", 'app/support/one.php');
        $index->addSource("<?php require __DIR__ . '/support/one.php'; function included() {}", 'app/two.php');
        $index->addSource('<?php function unrelated() {}', 'app/B.php');
        self::assertTrue($index->has('included', 'app/A.php'));
        self::assertTrue($index->has('included', 'app/support/one.php'));
        self::assertFalse($index->has('included', 'app/B.php'));
        self::assertFalse($index->has('unrelated', 'app/A.php'));
        $index->addSource("<?php return; require __DIR__ . '/two.php';", 'app/stopped.php');
        $index->addSource("<?php if (unknown_condition()) { require __DIR__ . '/two.php'; }", 'app/conditional.php');
        self::assertFalse($index->has('included', 'app/stopped.php'));
        self::assertFalse($index->has('included', 'app/conditional.php'));
    }

    public function testEagerVendorSyntaxFailuresAreReported(): void
    {
        $index = new FunctionIndex();
        $findings = $index->addVendorEntries([
            ['name' => 'vendor/p/start.php', 'contents' => "<?php require __DIR__ . '/invalid.php';"],
            ['name' => 'vendor/p/invalid.php', 'contents' => '<?php function broken( {'],
            ['name' => 'vendor/p/not-loaded.php', 'contents' => '<?php function broken( {'],
        ], ['vendor/p/start.php']);
        self::assertCount(1, $findings);
        self::assertSame('vendor/p/invalid.php', $findings[0]->file);
        self::assertSame(ErrorCode::IncompatibleWorkerSyntax, $findings[0]->code);
    }

    public function testTopLevelAndMigrationFunctionBodiesAreChecked(): void
    {
        $index = new FunctionIndex();
        $index->addSource('<?php namespace App; missing_top(); function migration() { missing_body(); }', 'migrations/001.php');
        $discovery = new DiscoveryResult([], []);
        $classifier = new SymbolClassifier($discovery, AllowedPackages::load(), [], null, $index);
        $findings = (new ClosureWalker($discovery, $classifier))->walk();
        self::assertCount(2, $findings);
        self::assertSame(['missing_top', 'missing_body'], array_map(static fn ($finding) => $finding->symbol, $findings));
    }

    public function testTopLevelCallsRespectDeclarationAndIncludeOrder(): void
    {
        $index = new FunctionIndex();
        $index->addSource(<<<'PHP'
            <?php
            guarded();
            if (!function_exists('guarded')) { function guarded() {} }
            guarded();
            included();
            require __DIR__ . '/functions.php';
            included();
            compiled();
            function compiled() {}
            function later() { guarded(); included(); }
            PHP, 'app/main.php');
        $index->addSource('<?php function included() {}', 'app/functions.php');
        $discovery = new DiscoveryResult([], []);
        $classifier = new SymbolClassifier($discovery, AllowedPackages::load(), [], null, $index);
        $findings = (new ClosureWalker($discovery, $classifier))->walk();
        self::assertSame(['guarded', 'included'], array_map(static fn ($finding) => $finding->symbol, $findings));
        self::assertSame([2, 5], array_map(static fn ($finding) => $finding->line, $findings));
    }

    public function testNamedMigrationClassMethodsAreChecked(): void
    {
        $index = new FunctionIndex();
        $index->addSource('<?php class Migration { public function up() { missing_migration_function(); } }', 'migrations/001.php');
        $discovery = new DiscoveryResult([], []);
        $classifier = new SymbolClassifier($discovery, AllowedPackages::load(), [], null, $index);
        $findings = (new ClosureWalker($discovery, $classifier))->walk();
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
