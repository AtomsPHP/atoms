<?php

declare(strict_types=1);

namespace Atoms\Cli\Tests\Build;

use Atoms\Cli\Build\Builder;
use Atoms\Cli\Tests\TestCase;

final class DeterminismTest extends TestCase
{
    public function testTwoFastBuildsAreByteIdentical(): void
    {
        $config = $this->sampleApp();
        $builder = new Builder();

        $one = $builder->build($config, $this->freshDir(), true);
        $two = $builder->build($config, $this->freshDir(), true);

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

    public function testBundleContainsOnlyWorldAFiles(): void
    {
        $result = (new Builder())->build($this->sampleApp(), $this->freshDir(), true);

        $tar = gzdecode((string) file_get_contents($result->bundlePath));
        self::assertIsString($tar);

        self::assertStringContainsString('app/Atoms/GameRoom.php', $tar);
        self::assertStringContainsString('app/Atoms/Shared/PlayerSnapshot.php', $tar);
        self::assertStringContainsString('atoms-composer.json', $tar);
        // Methods and AtomJob code stays in the monolith — never bundled.
        self::assertStringNotContainsString('GameRoom/Methods.php', $tar);
        self::assertStringNotContainsString('RecordGameResult.php', $tar);
    }
}
