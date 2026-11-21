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
