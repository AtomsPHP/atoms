<?php

declare(strict_types=1);

namespace Atoms\Cli\Tests\Build;

use Atoms\Cli\Build\Builder;
use Atoms\Cli\Build\VendorStage;
use Atoms\Cli\Config\AtomsJson;
use Atoms\Cli\Tests\Support\CannedComposer;
use Atoms\Cli\Tests\TestCase;
use Atoms\Errors\AtomsError;
use Atoms\Errors\ErrorCode;

final class BuilderVendorTest extends TestCase
{
    public function testAFullBuildShipsTheVendorTreeAndRecordsItInTheManifest(): void
    {
        $root = $this->tempCopy('sample-app');
        $config = AtomsJson::load($root . '/atoms.json');

        $result = (new Builder(runner: CannedComposer::runner()))->build($config, $this->freshDir());

        $tar = gzdecode((string) file_get_contents($result->bundlePath));
        self::assertIsString($tar);
        self::assertStringContainsString('vendor/acme/lib/src/Greeter.php', $tar);
        self::assertStringContainsString('vendor/atoms-vendor-autoload.php', $tar);

        self::assertSame(VendorStage::AUTOLOAD_PATH, $result->manifest['vendor']['autoload'] ?? null);
        self::assertSame(['acme/lib' => '1.2.3'], $result->manifest['vendor']['packages'] ?? null);
        self::assertNotNull($result->vendor);
        self::assertTrue($result->vendor->wroteLock);
    }

    public function testTwoFullBuildsAreByteIdenticalAndTheSecondComesFromTheCache(): void
    {
        $root = $this->tempCopy('sample-app');
        $config = AtomsJson::load($root . '/atoms.json');
        $runner = CannedComposer::runner();
        $builder = new Builder(runner: $runner);

        $one = $builder->build($config, $this->freshDir());
        $runsAfterFirst = \count($runner->runs);
        $two = $builder->build($config, $this->freshDir());

        self::assertSame($one->contentHash, $two->contentHash);
        self::assertSame(
            hash_file('sha256', $one->bundlePath),
            hash_file('sha256', $two->bundlePath),
        );
        self::assertSame($runsAfterFirst, \count($runner->runs), 'the second build resolves from the cache');
    }

    public function testAFastBuildWithDeclaredDependenciesRefusesWithTheCatalogCode(): void
    {
        $root = $this->tempCopy('sample-app');
        $config = AtomsJson::load($root . '/atoms.json');
        $runner = CannedComposer::runner();

        try {
            (new Builder(runner: $runner))->build($config, $this->freshDir(), fast: true);
            self::fail('--fast with a non-empty atoms-composer.json must refuse');
        } catch (AtomsError $e) {
            self::assertSame(ErrorCode::FastBuildWithDependencies, $e->errorCode);
            self::assertStringContainsString('ATOMS-E107', $e->getMessage());
            self::assertStringContainsString('1 package(s)', $e->getMessage());
        }

        self::assertSame([], $runner->runs, 'the refusal happens before any subprocess');
    }

    public function testAFastBuildWithDeclaredDependenciesStillRefusesWhenVendorCacheIsWarm(): void
    {
        $root = $this->tempCopy('sample-app');
        $config = AtomsJson::load($root . '/atoms.json');
        $runner = CannedComposer::runner();
        $builder = new Builder(runner: $runner);

        $builder->build($config, $this->freshDir());
        $runsAfterFull = \count($runner->runs);
        $fastOut = $this->freshDir();

        try {
            $builder->build($config, $fastOut, fast: true);
            self::fail('--fast with declared dependencies must refuse even when vendor cache is warm');
        } catch (AtomsError $e) {
            self::assertSame(ErrorCode::FastBuildWithDependencies, $e->errorCode);
            self::assertStringContainsString('ATOMS-E107', $e->getMessage());
            self::assertStringContainsString('1 package(s)', $e->getMessage());
        }

        self::assertSame($runsAfterFull, \count($runner->runs), 'no extra composer run on --fast refusal');
        self::assertSame([], glob($fastOut . '/*') ?: []);
    }

    public function testValidationErrorsTakePrecedenceOverFastBuildDependencyRefusal(): void
    {
        $root = $this->tempCopy('sample-app');
        file_put_contents($root . '/app/Atoms/GameRoom.php', <<<'PHP'
<?php
namespace App\Atoms;
final class GameRoom extends \Atoms\Atom {
    public function bad(): mixed { return new \App\Models\User(); }
}
PHP);
        $config = AtomsJson::load($root . '/atoms.json');
        $runner = CannedComposer::runner();

        try {
            (new Builder(runner: $runner))->build($config, $this->freshDir(), fast: true);
            self::fail('validation errors must take precedence over ATOMS-E107');
        } catch (AtomsError $e) {
            self::assertSame(ErrorCode::BundleRejected, $e->errorCode);
            self::assertStringContainsString('ATOMS-E012', $e->getMessage());
        }

        self::assertSame([], $runner->runs);
    }

    public function testAFastBuildWithNoDependenciesStillWorksAndShipsNoVendor(): void
    {
        $config = AtomsJson::load($this->fixtureDir('ws-app') . '/atoms.json');

        $result = (new Builder())->build($config, $this->freshDir(), fast: true);

        self::assertNull($result->vendor);
        self::assertArrayNotHasKey('vendor', $result->manifest);
        $tar = gzdecode((string) file_get_contents($result->bundlePath));
        self::assertIsString($tar);
        self::assertStringNotContainsString('atoms-vendor-autoload', $tar);
    }
}
