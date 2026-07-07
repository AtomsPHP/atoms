<?php

declare(strict_types=1);

namespace Atoms\Cli\Tests\Command;

use Atoms\Cli\Command\BuildCommand;
use Atoms\Cli\Tests\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class BuildCommandTest extends TestCase
{
    public function testBuildWritesBundleAndReportsHash(): void
    {
        $out = $this->freshDir();
        $tester = new CommandTester(new BuildCommand());
        $exit = $tester->execute([
            '--root' => $this->fixtureDir('sample-app'),
            '--fast' => true,
            '--out' => $out,
        ]);

        $display = $tester->getDisplay();
        self::assertSame(0, $exit);
        self::assertStringContainsString('content hash', $display);
        self::assertStringContainsString('atom types:    1', $display);

        $bundles = glob($out . '/bundle-*.tar.gz');
        self::assertNotFalse($bundles);
        self::assertCount(1, $bundles);
        self::assertFileExists($out . '/manifest.json');
    }
}
