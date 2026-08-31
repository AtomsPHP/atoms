<?php

declare(strict_types=1);

namespace Atoms\Cli\Tests\Build;

use Atoms\Cli\Build\VendorStage;
use Atoms\Cli\Process\ProcessResult;
use Atoms\Cli\Tests\Support\FakeProcessRunner;
use Atoms\Cli\Tests\TestCase;
use Atoms\Errors\AtomsError;
use Atoms\Errors\ErrorCode;

final class VendorStageTest extends TestCase
{
    /**
     * A FakeProcessRunner whose `composer install` materializes a canned
     * one-package vendor tree (class + function file + LICENSE + a readme
     * that must be pruned) with real Composer-shaped autoload output, in
     * whatever cwd the stage hands it.
     */
    private function fakeComposer(): FakeProcessRunner
    {
        $runner = new FakeProcessRunner(onPath: ['composer' => '/usr/bin/composer']);
        $runner->resultFor = static function (array $command, ?string $cwd): ?ProcessResult {
            if ($command[0] !== 'composer' || $cwd === null) {
                return null;
            }

            $v = $cwd . '/vendor';
            foreach ([$v . '/acme/lib/src', $v . '/composer'] as $dir) {
                mkdir($dir, 0777, true);
            }
            file_put_contents($cwd . '/composer.lock', "{\"canned\": true}\n");
            file_put_contents(
                $v . '/acme/lib/src/Greeter.php',
                "<?php\n\nnamespace Acme\\Lib;\n\nfinal class Greeter\n{\n    public static function greet(): string\n    {\n        return 'hello from vendor';\n    }\n}\n",
            );
            file_put_contents(
                $v . '/acme/lib/functions.php',
                "<?php\n\nif (!function_exists('acme_vendor_greet')) {\n    function acme_vendor_greet(): string\n    {\n        return 'hello from a function file';\n    }\n}\n",
            );
            file_put_contents($v . '/acme/lib/LICENSE', "MIT\n");
            file_put_contents($v . '/acme/lib/readme.md', "not shipped\n");
            file_put_contents(
                $v . '/composer/autoload_classmap.php',
                "<?php\n\n\$vendorDir = dirname(__DIR__);\n\$baseDir = dirname(\$vendorDir);\n\nreturn [\n    'Acme\\\\Lib\\\\Greeter' => \$vendorDir . '/acme/lib/src/Greeter.php',\n];\n",
            );
            file_put_contents(
                $v . '/composer/autoload_files.php',
                "<?php\n\n\$vendorDir = dirname(__DIR__);\n\$baseDir = dirname(\$vendorDir);\n\nreturn [\n    'deadbeef' => \$vendorDir . '/acme/lib/functions.php',\n];\n",
            );
            file_put_contents(
                $v . '/composer/installed.json',
                "{\"packages\": [{\"name\": \"acme/lib\", \"version\": \"1.2.3\"}]}\n",
            );
            file_put_contents($v . '/autoload.php', "<?php // composer runtime, must be pruned\n");

            return new ProcessResult(0, '', '');
        };

        return $runner;
    }

    public function testResolveShipsPhpAndLicensesPrunesTheRestAndWritesTheLockBack(): void
    {
        $root = $this->tempCopy('sample-app');
        $tree = (new VendorStage($this->fakeComposer()))->resolve($root);

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
        $tree = (new VendorStage($this->fakeComposer()))->resolve($root);

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
        $runner = $this->fakeComposer();
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
