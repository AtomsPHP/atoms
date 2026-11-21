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
        self::assertSame(['vendor/acme/lib/functions.php'], $tree->autoloadFiles);
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
        self::assertSame($first->autoloadFiles, $second->autoloadFiles);
        self::assertFalse($second->wroteLock);
    }

    public function testTheReadOnlyCacheReportsShippedEagerFilesInComposerOrder(): void
    {
        $root = $this->tempCopy('sample-app');
        $runner = new FakeProcessRunner(onPath: []);
        $cachedStage = new VendorStage($runner);

        self::assertNull($cachedStage->cached($root));
        self::assertFileDoesNotExist($root . '/atoms-composer.lock');
        self::assertDirectoryDoesNotExist($root . '/.atoms/vendor-cache');

        $stage = new VendorStage(CannedComposer::runner([
            'acme/lib/z-functions.php' => '<?php throw new \RuntimeException("Eager file executed during analysis");',
            'acme/lib/pruned.inc' => '<?php function pruned() {}',
            'composer/autoload_files.php' => <<<'PHP'
                <?php
                $vendorDir = dirname(__DIR__);
                return [
                    'first' => $vendorDir . '/acme/lib/z-functions.php',
                    'second' => $vendorDir . '/acme/lib/functions.php',
                    'pruned' => $vendorDir . '/acme/lib/pruned.inc',
                ];
                PHP,
        ]));
        $tree = $stage->resolve($root);
        self::assertSame(['vendor/acme/lib/z-functions.php', 'vendor/acme/lib/functions.php'], $tree->autoloadFiles);

        $cached = $cachedStage->cached($root);
        self::assertNotNull($cached);
        self::assertSame($tree->autoloadFiles, $cached->autoloadFiles);
        self::assertSame($tree->entries, $cached->entries);
        self::assertFalse($cached->wroteLock);

        file_put_contents($root . '/atoms-composer.lock', "{\"changed\": true}\n");
        self::assertNull($cachedStage->cached($root));
        self::assertSame([], $runner->runs);
    }

    public function testComposerRuntimeFilesReferencedByRealClassmapsAreKept(): void
    {
        $root = $this->tempCopy('sample-app');
        $tree = (new VendorStage(CannedComposer::runner()))->resolve($root);

        // Real optimized classmaps map Composer\InstalledVersions into
        // vendor/composer/. Pruning the file while keeping the classmap
        // entry breaks any dependency that uses Composer's runtime API.
        $names = array_column($tree->entries, 'name');
        self::assertContains('vendor/composer/InstalledVersions.php', $names);
        self::assertContains('vendor/composer/installed.php', $names);

        $autoload = array_column($tree->entries, 'contents', 'name')[VendorStage::AUTOLOAD_PATH];
        self::assertStringContainsString("'Composer\\\\InstalledVersions'", $autoload);
        self::assertStringContainsString('/composer/InstalledVersions.php', $autoload);
    }

    public function testAnUnreadableCacheFileIsAMissNotABuildFailure(): void
    {
        $root = $this->tempCopy('sample-app');
        $runner = CannedComposer::runner();
        $stage = new VendorStage($runner);

        $stage->resolve($root);
        $runsAfterFirst = \count($runner->runs);

        // A read failure mid-cache — a permissions problem, or a concurrent
        // replacement racing the reader — must degrade to a miss.
        $caches = glob($root . '/.atoms/vendor-cache/*');
        self::assertNotFalse($caches);
        $victim = $caches[0] . '/files/vendor/acme/lib/src/Greeter.php';
        self::assertTrue(chmod($victim, 0o000));
        if (@file_get_contents($victim) !== false) {
            chmod($victim, 0o644);
            self::markTestSkipped('this user can read a mode-000 file (running as root?)');
        }

        try {
            $tree = $stage->resolve($root);
        } finally {
            chmod($victim, 0o644);
        }

        self::assertGreaterThan($runsAfterFirst, \count($runner->runs), 'an unreadable cache must re-resolve through composer');
        self::assertContains('vendor/acme/lib/src/Greeter.php', array_column($tree->entries, 'name'));
    }

    public function testAMisconfiguredComposerTimeoutRefusesWithTheCatalogCode(): void
    {
        $root = $this->tempCopy('sample-app');
        putenv('ATOMS_COMPOSER_TIMEOUT=-1');

        try {
            (new VendorStage(CannedComposer::runner()))->resolve($root);
            self::fail('a non-positive timeout must refuse');
        } catch (AtomsError $e) {
            self::assertSame(ErrorCode::VendorResolutionFailed, $e->errorCode);
            self::assertStringContainsString('ATOMS_COMPOSER_TIMEOUT', $e->getMessage());
        } finally {
            putenv('ATOMS_COMPOSER_TIMEOUT');
        }
    }

    public function testATamperedCacheIsAMissThatReResolvesNotAPoisonedTree(): void
    {
        $root = $this->tempCopy('sample-app');
        $runner = CannedComposer::runner();
        $stage = new VendorStage($runner);

        $stage->resolve($root);
        $runsAfterFirst = \count($runner->runs);

        // Alter a cached file without touching atoms-composer.json or the lock.
        $caches = glob($root . '/.atoms/vendor-cache/*');
        self::assertNotFalse($caches);
        self::assertCount(1, $caches);
        $victim = $caches[0] . '/files/vendor/acme/lib/src/Greeter.php';
        self::assertFileExists($victim);
        file_put_contents($victim, "<?php // poisoned\n");

        self::assertNull($stage->cached($root));
        self::assertSame($runsAfterFirst, \count($runner->runs), 'reading a corrupt cache runs no subprocess');

        $tree = $stage->resolve($root);

        self::assertGreaterThan($runsAfterFirst, \count($runner->runs), 'a tampered cache must re-resolve through composer');
        $contents = array_column($tree->entries, 'contents', 'name');
        self::assertStringNotContainsString('poisoned', $contents['vendor/acme/lib/src/Greeter.php']);
    }

    public function testRelativePathRepositoriesAreRebasedAndForcedToCopy(): void
    {
        $staged = VendorStage::stagedComposerJson('/home/me/app', json_encode([
            'require' => ['acme/local' => '*'],
            'repositories' => [
                ['type' => 'path', 'url' => 'packages/local'],
                ['type' => 'path', 'url' => '/already/absolute'],
                ['type' => 'composer', 'url' => 'https://example.test'],
            ],
        ], JSON_THROW_ON_ERROR));

        $decoded = json_decode($staged, true, 16, JSON_THROW_ON_ERROR);

        // Relative path urls resolve against the project root, not the temp
        // dir composer runs in…
        self::assertSame('/home/me/app/packages/local', $decoded['repositories'][0]['url']);
        self::assertSame('/already/absolute', $decoded['repositories'][1]['url']);
        // …and symlink installs forced off: a symlinked package directory is
        // invisible to the tree walk and rejected by realpath().
        self::assertFalse($decoded['repositories'][0]['options']['symlink']);
        self::assertFalse($decoded['repositories'][1]['options']['symlink']);
        self::assertArrayNotHasKey('options', $decoded['repositories'][2]);
        self::assertSame(VendorStage::workerPlatform(), $decoded['config']['platform'] ?? null);
    }

    public function testComposerCommandEnforcesPlatformRequirements(): void
    {
        $command = VendorStage::composerCommand();

        self::assertNotContains('--ignore-platform-reqs', $command);
        self::assertContains('--no-dev', $command);
        self::assertContains('--no-scripts', $command);
        self::assertContains('--no-plugins', $command);
        self::assertContains('--optimize-autoloader', $command);
    }

    public function testStagedComposerJsonInjectsWorkerPlatformEvenWithoutRepositories(): void
    {
        $staged = VendorStage::stagedComposerJson('/home/me/app', json_encode([
            'require' => ['ramsey/uuid' => '^4.7'],
        ], JSON_THROW_ON_ERROR));

        $decoded = json_decode($staged, true, 16, JSON_THROW_ON_ERROR);
        $platform = $decoded['config']['platform'] ?? null;
        self::assertIsArray($platform);
        self::assertSame(VendorStage::WORKER_PHP_VERSION, $platform['php'] ?? null);

        $extensionsFile = \dirname(__DIR__, 2) . '/resources/runtime-extensions.json';
        /** @var array{extensions: list<string>} $rawExtensions */
        $rawExtensions = json_decode((string) file_get_contents($extensionsFile), true, 16, JSON_THROW_ON_ERROR);
        foreach ($rawExtensions['extensions'] as $ext) {
            $lower = strtolower($ext);
            if ($lower === 'core' || $lower === 'standard') {
                self::assertArrayNotHasKey('ext-' . $lower, $platform);
                continue;
            }
            self::assertSame(VendorStage::WORKER_PHP_VERSION, $platform['ext-' . $lower] ?? null);
        }
    }

    public function testStagedComposerLockInjectsWorkerPlatformOverridesWithoutCorruptingEmptyObjects(): void
    {
        $rawLock = json_encode([
            'content-hash' => 'abc123',
            'packages' => [],
            'packages-dev' => [],
            'platform' => new \stdClass(),
            'platform-dev' => new \stdClass(),
            'platform-overrides' => ['php' => '8.5.0'],
        ], JSON_THROW_ON_ERROR);

        $staged = VendorStage::stagedComposerLock($rawLock);

        self::assertStringContainsString('"platform": {}', $staged);
        self::assertStringContainsString('"platform-dev": {}', $staged);
        self::assertStringContainsString('"packages-dev": []', $staged);

        $decoded = json_decode($staged, true, 16, JSON_THROW_ON_ERROR);
        self::assertSame(VendorStage::workerPlatform(), $decoded['platform-overrides'] ?? null);
    }

    public function testCacheKeyIncludesWorkerPhpVersion(): void
    {
        $json = "{\"require\":{\"acme/lib\":\"^1.0\"}}\n";
        $lock = "{\"canned\": true}\n";

        self::assertNotSame(
            VendorStage::cacheKey($json, $lock, '8.3.32'),
            VendorStage::cacheKey($json, $lock, '8.4.0'),
        );
        self::assertNotSame(
            hash('sha256', $json . "\0" . $lock),
            VendorStage::cacheKey($json, $lock),
            'legacy caches created before platform pinning must miss',
        );

        $root = $this->tempCopy('sample-app');
        $runner = CannedComposer::runner();
        (new VendorStage($runner, '8.3.32'))->resolve($root);
        $runsAfterFirst = \count($runner->runs);

        (new VendorStage($runner, '8.4.0'))->resolve($root);
        self::assertGreaterThan(
            $runsAfterFirst,
            \count($runner->runs),
            'changing the Worker PHP version must invalidate the vendor cache',
        );
    }

    public function testRealComposerResolvesVersionCompatibleWithWorkerPhpInsteadOfHostPhp(): void
    {
        $this->requireRealComposer();

        $root = $this->freshDir();
        $this->writePathPackage($root . '/packages/widget-v1', 'acme/widget', '1.0.0', ['php' => '^8.3']);
        $this->writePathPackage($root . '/packages/widget-v2', 'acme/widget', '2.0.0', ['php' => '>=8.4.1']);
        file_put_contents($root . '/atoms-composer.json', json_encode([
            'require' => ['acme/widget' => '*'],
            'repositories' => [
                ['type' => 'path', 'url' => 'packages/widget-v1'],
                ['type' => 'path', 'url' => 'packages/widget-v2'],
                ['packagist.org' => false],
            ],
        ], JSON_THROW_ON_ERROR));

        $stage = new VendorStage(new \Atoms\Cli\Process\SymfonyProcessRunner());
        $tree = $stage->resolve($root);

        self::assertSame(['acme/widget' => '1.0.0'], $tree->packages);
        self::assertTrue($tree->wroteLock);

        /** @var array<string, mixed> $lock */
        $lock = json_decode((string) file_get_contents($root . '/atoms-composer.lock'), true, 64, JSON_THROW_ON_ERROR);
        self::assertSame(VendorStage::WORKER_PHP_VERSION, $lock['platform-overrides']['php'] ?? null);

        // Clear cache and re-run from the written lockfile to prove repeat
        // verification passes cleanly against the Worker platform.
        $this->removeDir($root . '/.atoms');
        $reinstalled = $stage->resolve($root);
        self::assertSame(['acme/widget' => '1.0.0'], $reinstalled->packages);
        self::assertFalse($reinstalled->wroteLock);
    }

    public function testRealComposerRejectsFreshSolveWhenPackageRequiresNewerPhpThanWorker(): void
    {
        $this->requireRealComposer();

        $root = $this->freshDir();
        $this->writePathPackage($root . '/packages/php84-only', 'acme/php84-only', '1.0.0', ['php' => '>=8.4.1']);
        file_put_contents($root . '/atoms-composer.json', json_encode([
            'require' => ['acme/php84-only' => '*'],
            'repositories' => [
                ['type' => 'path', 'url' => 'packages/php84-only'],
                ['packagist.org' => false],
            ],
        ], JSON_THROW_ON_ERROR));

        try {
            (new VendorStage(new \Atoms\Cli\Process\SymfonyProcessRunner()))->resolve($root);
            self::fail('a package requiring PHP >= 8.4.1 must fail against the PHP 8.3.32 Worker');
        } catch (AtomsError $e) {
            self::assertSame(ErrorCode::VendorResolutionFailed, $e->errorCode);
            self::assertStringContainsString('acme/php84-only', $e->getMessage());
            self::assertStringContainsString('php >=8.4.1', $e->getMessage());
            self::assertStringContainsString(VendorStage::WORKER_PHP_VERSION, $e->getMessage());
        }

        self::assertFileDoesNotExist($root . '/atoms-composer.lock');
    }

    public function testRealComposerRejectsExistingLockfileWithIncompatiblePhpRequirement(): void
    {
        $this->requireRealComposer();

        $root = $this->freshDir();
        $pkgDir = $root . '/packages/php84-only';
        $this->writePathPackage($pkgDir, 'acme/php84-only', '1.0.0', ['php' => '>=8.4.1']);
        file_put_contents($root . '/atoms-composer.json', json_encode([
            'require' => ['acme/php84-only' => '*'],
            'repositories' => [
                ['type' => 'path', 'url' => 'packages/php84-only'],
                ['packagist.org' => false],
            ],
        ], JSON_THROW_ON_ERROR));

        // Legacy lockfile generated before platform pinning (no platform-overrides),
        // locking a package whose requirement exceeds the Worker's PHP 8.3.32.
        $lockJson = json_encode([
            '_readme' => ['Locked before platform pinning'],
            'content-hash' => '00000000000000000000000000000000',
            'packages' => [
                [
                    'name' => 'acme/php84-only',
                    'version' => '1.0.0',
                    'dist' => [
                        'type' => 'path',
                        'url' => $pkgDir,
                        'reference' => 'deadbeef',
                    ],
                    'require' => ['php' => '>=8.4.1'],
                    'type' => 'library',
                    'autoload' => ['psr-4' => ['Acme\\Php84Only\\' => 'src/']],
                    'transport-options' => ['symlink' => false],
                ],
            ],
            'packages-dev' => [],
            'aliases' => [],
            'minimum-stability' => 'stable',
            'stability-flags' => new \stdClass(),
            'prefer-stable' => false,
            'prefer-lowest' => false,
            'platform' => new \stdClass(),
            'platform-dev' => new \stdClass(),
            'plugin-api-version' => '2.6.0',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        file_put_contents($root . '/atoms-composer.lock', $lockJson);

        try {
            (new VendorStage(new \Atoms\Cli\Process\SymfonyProcessRunner()))->resolve($root);
            self::fail('an existing lockfile with PHP >= 8.4.1 must fail against the PHP 8.3.32 Worker');
        } catch (AtomsError $e) {
            self::assertSame(ErrorCode::VendorResolutionFailed, $e->errorCode);
            self::assertStringContainsString('acme/php84-only', $e->getMessage());
            self::assertStringContainsString('php >=8.4.1', $e->getMessage());
            self::assertStringContainsString(VendorStage::WORKER_PHP_VERSION, $e->getMessage());
        }

        self::assertSame($lockJson, file_get_contents($root . '/atoms-composer.lock'));
    }

    public function testRealComposerSatisfiesBundledWorkerExtensionsAndRejectsMissingOnes(): void
    {
        $this->requireRealComposer();

        // Bundled extensions from runtime-extensions.json (including ext-uv,
        // which is rarely present on host PHP) must resolve cleanly.
        $okRoot = $this->freshDir();
        $this->writePathPackage($okRoot . '/packages/worker-exts', 'acme/worker-exts', '1.0.0', [
            'php' => '^8.3',
            'ext-pdo' => '*',
            'ext-pdo_sqlite' => '*',
            'ext-mbstring' => '*',
            'ext-uv' => '*',
        ]);
        file_put_contents($okRoot . '/atoms-composer.json', json_encode([
            'require' => ['acme/worker-exts' => '*'],
            'repositories' => [
                ['type' => 'path', 'url' => 'packages/worker-exts'],
                ['packagist.org' => false],
            ],
        ], JSON_THROW_ON_ERROR));

        $tree = (new VendorStage(new \Atoms\Cli\Process\SymfonyProcessRunner()))->resolve($okRoot);
        self::assertSame(['acme/worker-exts' => '1.0.0'], $tree->packages);

        // An unavailable extension must fail with the offending package and requirement.
        $badRoot = $this->freshDir();
        $this->writePathPackage($badRoot . '/packages/missing-ext', 'acme/missing-ext', '1.0.0', [
            'php' => '^8.3',
            'ext-atoms_nonexistent' => '*',
        ]);
        file_put_contents($badRoot . '/atoms-composer.json', json_encode([
            'require' => ['acme/missing-ext' => '*'],
            'repositories' => [
                ['type' => 'path', 'url' => 'packages/missing-ext'],
                ['packagist.org' => false],
            ],
        ], JSON_THROW_ON_ERROR));

        try {
            (new VendorStage(new \Atoms\Cli\Process\SymfonyProcessRunner()))->resolve($badRoot);
            self::fail('a package requiring an unavailable extension must fail');
        } catch (AtomsError $e) {
            self::assertSame(ErrorCode::VendorResolutionFailed, $e->errorCode);
            self::assertStringContainsString('acme/missing-ext', $e->getMessage());
            self::assertStringContainsString('ext-atoms_nonexistent', $e->getMessage());
        }
    }

    /**
     * Real composer, a relative path repository, packagist disabled — fully
     * offline. Without the staging rewrite, resolve() succeeds with an empty
     * tree and the bundle deploys unable to load the package.
     */
    public function testARealComposerPathRepositoryProducesAShippableTree(): void
    {
        $composer = (new \Symfony\Component\Process\ExecutableFinder())->find('composer');
        if ($composer === null) {
            self::markTestSkipped('composer is not on PATH; this leg drives the real binary (offline: path repo + packagist disabled).');
        }

        $root = $this->freshDir();
        mkdir($root . '/packages/local/src', 0777, true);
        file_put_contents($root . '/packages/local/composer.json', json_encode([
            'name' => 'acme/local',
            'version' => '1.0.0',
            'autoload' => ['psr-4' => ['Acme\\Local\\' => 'src/']],
        ], JSON_THROW_ON_ERROR));
        file_put_contents(
            $root . '/packages/local/src/Widget.php',
            "<?php\n\nnamespace Acme\\Local;\n\nfinal class Widget\n{\n}\n",
        );
        file_put_contents($root . '/atoms-composer.json', json_encode([
            'require' => ['acme/local' => '*'],
            'repositories' => [
                ['type' => 'path', 'url' => 'packages/local'],
                ['packagist.org' => false],
            ],
        ], JSON_THROW_ON_ERROR));

        $tree = (new VendorStage(new \Atoms\Cli\Process\SymfonyProcessRunner()))->resolve($root);

        $names = array_column($tree->entries, 'name');
        self::assertContains('vendor/acme/local/src/Widget.php', $names, 'the path package must ship as real files');
        self::assertArrayHasKey('acme/local', $tree->packages);

        $autoload = array_column($tree->entries, 'contents', 'name')[VendorStage::AUTOLOAD_PATH];
        self::assertStringContainsString("'Acme\\\\Local\\\\Widget'", $autoload);
        self::assertStringContainsString('/acme/local/src/Widget.php', $autoload);
    }

    public function testAFailedLockWriteBackRefusesInsteadOfClaimingReproducibility(): void
    {
        $root = $this->tempCopy('sample-app');
        // A directory squatting on the lock path makes the write-back fail.
        mkdir($root . '/atoms-composer.lock');

        $this->expectException(AtomsError::class);
        $this->expectExceptionMessageMatches('/ATOMS-E079.*could not (read|write)/s');

        (new VendorStage(CannedComposer::runner()))->resolve($root);
    }

    public function testANonUtf8VendorFileRefusesInsteadOfShippingBytesTheTranslatorWouldMangle(): void
    {
        $root = $this->tempCopy('sample-app');
        $runner = CannedComposer::runner(['acme/lib/src/Latin1.php' => "<?php // caf\xE9\n"]);

        $this->expectException(AtomsError::class);
        $this->expectExceptionMessageMatches('/ATOMS-E079.*Latin1\.php is not valid UTF-8/s');

        (new VendorStage($runner))->resolve($root);
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

    private function requireRealComposer(): void
    {
        $composer = (new \Symfony\Component\Process\ExecutableFinder())->find('composer');
        if ($composer === null) {
            self::markTestSkipped('composer is not on PATH; this leg drives the real binary (offline: path repo + packagist disabled).');
        }
    }

    /**
     * @param array<string, string> $require
     */
    private function writePathPackage(string $dir, string $name, string $version, array $require): void
    {
        $parts = explode('/', $name);
        $className = str_replace('-', '', ucwords($parts[1] ?? 'Pkg', '-'));
        $namespace = ucfirst($parts[0]) . '\\' . $className;

        mkdir($dir . '/src', 0777, true);
        file_put_contents($dir . '/composer.json', json_encode([
            'name' => $name,
            'version' => $version,
            'require' => $require,
            'autoload' => ['psr-4' => [$namespace . '\\' => 'src/']],
        ], JSON_THROW_ON_ERROR));
        file_put_contents(
            $dir . '/src/Stub.php',
            "<?php\n\nnamespace {$namespace};\n\nfinal class Stub\n{\n}\n",
        );
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        /** @var \SplFileInfo $item */
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
