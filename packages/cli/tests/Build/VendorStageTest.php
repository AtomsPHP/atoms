<?php

declare(strict_types=1);

namespace Atoms\Cli\Tests\Build;

use Atoms\Cli\Build\VendorStage;
use Atoms\Cli\Process\ProcessResult;
use Atoms\Cli\Tests\Support\CannedComposer;
use Atoms\Cli\Tests\Support\FakeProcessRunner;
use Atoms\Cli\Tests\TestCase;
use Atoms\Errors\AtomsError;
use Atoms\Errors\ErrorCode;

final class VendorStageTest extends TestCase
{
    public function testResolveShipsPhpAndLicensesPrunesTheRestAndWritesTheLockBack(): void
    {
        $root = $this->tempCopy('sample-app');
        $tree = (new VendorStage(CannedComposer::runner()))->resolve($root);

        $names = array_column($tree->entries, 'name');
        self::assertContains('vendor/acme/lib/src/Greeter.php', $names);
        self::assertContains('vendor/acme/lib/functions.php', $names);
        self::assertContains('vendor/acme/lib/LICENSE', $names);
        self::assertContains(VendorStage::AUTOLOAD_PATH, $names);
        self::assertNotContains('vendor/acme/lib/readme.md', $names);
        self::assertNotContains('vendor/autoload.php', $names);
        self::assertNotContains('vendor/composer/autoload_classmap.php', $names);

        self::assertSame($names, array_values(array_unique($names)));
        $sorted = $names;
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $names, 'entries are sorted by name');

        self::assertSame(['acme/lib' => '1.2.3'], $tree->packages);
        self::assertTrue($tree->wroteLock);
        self::assertSame("{\"canned\": true}\n", file_get_contents($root . '/atoms-composer.lock'));
    }

    public function testTheGeneratedAutoloadFileActuallyLoadsTheTree(): void
    {
        $root = $this->tempCopy('sample-app');
        $tree = (new VendorStage(CannedComposer::runner()))->resolve($root);

        $mount = $this->freshDir();
        foreach ($tree->entries as $entry) {
            $path = $mount . '/' . $entry['name'];
            if (!is_dir(\dirname($path))) {
                mkdir(\dirname($path), 0777, true);
            }
            file_put_contents($path, $entry['contents']);
        }

        require $mount . '/' . VendorStage::AUTOLOAD_PATH;

        self::assertTrue(\function_exists('acme_vendor_greet'), 'function files are required eagerly');
        self::assertSame('hello from a function file', \acme_vendor_greet());
        self::assertTrue(class_exists('Acme\\Lib\\Greeter'), 'classmap classes autoload');
        self::assertSame('hello from vendor', \Acme\Lib\Greeter::greet());
    }

    public function testASecondResolveWithTheLockPresentComesFromTheCacheWithoutComposer(): void
    {
        $root = $this->tempCopy('sample-app');
        $runner = CannedComposer::runner();
        $stage = new VendorStage($runner);

        $first = $stage->resolve($root);
        $composerRuns = \count($runner->runs);

        $second = $stage->resolve($root);

        self::assertSame($composerRuns, \count($runner->runs), 'the cache hit runs no subprocess');
        self::assertSame(
            array_column($first->entries, 'contents', 'name'),
            array_column($second->entries, 'contents', 'name'),
        );
        self::assertSame($first->packages, $second->packages);
        self::assertFalse($second->wroteLock);
    }

    public function testPrunedDataLookingFilesAreSurfacedByNameAndSurviveTheCache(): void
    {
        $root = $this->tempCopy('sample-app');
        $runner = CannedComposer::runner([
            'acme/lib/data/tlds.txt' => "com\norg\n",
            'acme/lib/data/rules.json' => "{}\n",
            'acme/lib/composer.json' => "{}\n",
            'acme/lib/CHANGELOG.md' => "history\n",
        ]);
        $stage = new VendorStage($runner);

        $tree = $stage->resolve($root);

        // Data-looking prunes are named; metadata/docs prunes are not noise.
        self::assertSame(
            ['vendor/acme/lib/data/rules.json', 'vendor/acme/lib/data/tlds.txt'],
            $tree->prunedDataFiles,
        );
        self::assertNotContains('vendor/acme/lib/data/tlds.txt', array_column($tree->entries, 'name'));

        // A cache hit reports the same prunes — the notice must not vanish
        // just because composer did not run this time.
        $cached = $stage->resolve($root);
        self::assertFalse($cached->wroteLock);
        self::assertSame($tree->prunedDataFiles, $cached->prunedDataFiles);
    }

    public function testAFailedComposerInstallRefusesWithTheCatalogCode(): void
    {
        $root = $this->tempCopy('sample-app');
        $runner = new FakeProcessRunner(
            new ProcessResult(1, '', 'Your requirements could not be resolved.'),
            onPath: ['composer' => '/usr/bin/composer'],
        );

        try {
            (new VendorStage($runner))->resolve($root);
            self::fail('a failed install should refuse');
        } catch (AtomsError $e) {
            self::assertSame(ErrorCode::VendorResolutionFailed, $e->errorCode);
            self::assertStringContainsString('ATOMS-E079', $e->getMessage());
            self::assertStringContainsString('could not be resolved', $e->getMessage());
        }
    }

    public function testComposerMissingFromPathWithNoCacheRefusesLoudly(): void
    {
        $root = $this->tempCopy('sample-app');
        $runner = new FakeProcessRunner(onPath: []);

        $this->expectException(AtomsError::class);
        $this->expectExceptionMessageMatches('/ATOMS-E079.*not on PATH/s');

        (new VendorStage($runner))->resolve($root);
    }
}
