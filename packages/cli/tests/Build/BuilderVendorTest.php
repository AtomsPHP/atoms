<?php

declare(strict_types=1);

namespace Atoms\Cli\Tests\Build;

use Atoms\Cli\Build\Builder;
use Atoms\Cli\Build\VendorStage;
use Atoms\Cli\Config\AtomsJson;
use Atoms\Cli\Process\ProcessResult;
use Atoms\Cli\Tests\Support\FakeProcessRunner;
use Atoms\Cli\Tests\TestCase;

final class BuilderVendorTest extends TestCase
{
    private function fakeComposer(): FakeProcessRunner
    {
        $runner = new FakeProcessRunner(onPath: ['composer' => '/usr/bin/composer']);
        $runner->resultFor = static function (array $command, ?string $cwd): ?ProcessResult {
            if ($command[0] !== 'composer' || $cwd === null) {
                return null;
            }

            $v = $cwd . '/vendor';
            mkdir($v . '/acme/lib', 0777, true);
            mkdir($v . '/composer', 0777, true);
            file_put_contents($cwd . '/composer.lock', "{\"canned\": true}\n");
            file_put_contents($v . '/acme/lib/Widget.php', "<?php\n\nnamespace Acme\\Lib;\n\nfinal class Widget\n{\n}\n");
            file_put_contents(
                $v . '/composer/autoload_classmap.php',
                "<?php\n\n\$vendorDir = dirname(__DIR__);\n\nreturn ['Acme\\\\Lib\\\\Widget' => \$vendorDir . '/acme/lib/Widget.php'];\n",
            );
            file_put_contents($v . '/composer/installed.json', "{\"packages\": [{\"name\": \"acme/lib\", \"version\": \"2.0.0\"}]}\n");

            return new ProcessResult(0, '', '');
        };

        return $runner;
    }

    public function testAFullBuildShipsTheVendorTreeAndRecordsItInTheManifest(): void
    {
        $root = $this->tempCopy('sample-app');
        $config = AtomsJson::load($root . '/atoms.json');

        $result = (new Builder(runner: $this->fakeComposer()))->build($config, $this->freshDir());

        $tar = gzdecode((string) file_get_contents($result->bundlePath));
        self::assertIsString($tar);
        self::assertStringContainsString('vendor/acme/lib/Widget.php', $tar);
        self::assertStringContainsString('vendor/atoms-vendor-autoload.php', $tar);

        self::assertSame(VendorStage::AUTOLOAD_PATH, $result->manifest['vendor']['autoload'] ?? null);
        self::assertSame(['acme/lib' => '2.0.0'], $result->manifest['vendor']['packages'] ?? null);
        self::assertNotNull($result->vendor);
        self::assertTrue($result->vendor->wroteLock);
    }

    public function testTwoFullBuildsAreByteIdenticalAndTheSecondComesFromTheCache(): void
    {
        $root = $this->tempCopy('sample-app');
        $config = AtomsJson::load($root . '/atoms.json');
        $runner = $this->fakeComposer();
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

    public function testAFastBuildShipsNoVendorAndDeclaresNoneInTheManifest(): void
    {
        $root = $this->tempCopy('sample-app');
        $config = AtomsJson::load($root . '/atoms.json');

        $result = (new Builder(runner: $this->fakeComposer()))->build($config, $this->freshDir(), fast: true);

        self::assertNull($result->vendor);
        self::assertArrayNotHasKey('vendor', $result->manifest);
        $tar = gzdecode((string) file_get_contents($result->bundlePath));
        self::assertIsString($tar);
        self::assertStringNotContainsString('atoms-vendor-autoload', $tar);
    }
}
