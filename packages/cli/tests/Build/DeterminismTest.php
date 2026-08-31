<?php

declare(strict_types=1);

namespace Atoms\Cli\Tests\Build;

use Atoms\Cli\Build\Builder;
use Atoms\Cli\Config\AtomsJson;
use Atoms\Cli\Tests\Support\CannedComposer;
use Atoms\Cli\Tests\TestCase;

final class DeterminismTest extends TestCase
{
    /**
     * Full builds (sample-app declares a dependency, so --fast now refuses;
     * see BuilderVendorTest): composer is the CannedComposer fake, and the
     * builds run against a temp copy because the vendor stage writes
     * atoms-composer.lock and .atoms/vendor-cache into the project root.
     */
    public function testTwoBuildsAreByteIdentical(): void
    {
        $root = $this->tempCopy('sample-app');
        $config = AtomsJson::load($root . '/atoms.json');
        $builder = new Builder(runner: CannedComposer::runner());

        $one = $builder->build($config, $this->freshDir());
        $two = $builder->build($config, $this->freshDir());

        self::assertSame($one->contentHash, $two->contentHash);
        self::assertSame(
            hash_file('sha256', $one->bundlePath),
            hash_file('sha256', $two->bundlePath),
            'identical trees must produce byte-identical bundles',
        );
        self::assertNotSame(
            \dirname($one->bundlePath),
            \dirname($two->bundlePath),
            'the two builds must have used different output directories',
        );
    }

    public function testBundleContainsOnlyWorldAAndVendorFiles(): void
    {
        $root = $this->tempCopy('sample-app');
        $result = (new Builder(runner: CannedComposer::runner()))
            ->build(AtomsJson::load($root . '/atoms.json'), $this->freshDir());

        $tar = gzdecode((string) file_get_contents($result->bundlePath));
        self::assertIsString($tar);

        self::assertStringContainsString('app/Atoms/GameRoom.php', $tar);
        self::assertStringContainsString('app/Atoms/Shared/PlayerSnapshot.php', $tar);
        self::assertStringContainsString('atoms-composer.json', $tar);
        self::assertStringContainsString('vendor/atoms-vendor-autoload.php', $tar);
        // Methods and AtomJob code stays in the monolith — never bundled.
        self::assertStringNotContainsString('GameRoom/Methods.php', $tar);
        self::assertStringNotContainsString('RecordGameResult.php', $tar);
    }
}
