<?php

declare(strict_types=1);

namespace Atoms\Cli\Tests\Command;

use Atoms\Cli\Build\Builder;
use Atoms\Cli\Command\BuildCommand;
use Atoms\Cli\Tests\Support\CannedComposer;
use Atoms\Cli\Tests\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class BuildCommandTest extends TestCase
{
    public function testBuildWritesBundleAndReportsHash(): void
    {
        $out = $this->freshDir();
        // A temp copy: the vendor stage writes atoms-composer.lock and
        // .atoms/vendor-cache into the project root, and sample-app declares
        // a dependency so --fast would refuse (ATOMS-E107).
        $root = $this->tempCopy('sample-app');
        $tester = new CommandTester(new BuildCommand(new Builder(runner: CannedComposer::runner())));
        $exit = $tester->execute([
            '--root' => $root,
            '--out' => $out,
        ]);

        $display = $tester->getDisplay();
        self::assertSame(0, $exit);
        self::assertStringContainsString('content hash', $display);
        self::assertStringContainsString('atom types:    1', $display);
        self::assertStringContainsString('1 package(s) bundled, atoms-composer.lock written — commit it', $display);

        $bundles = glob($out . '/bundle-*.tar.gz');
        self::assertNotFalse($bundles);
        self::assertCount(1, $bundles);
        self::assertFileExists($out . '/manifest.json');
    }

    public function testPrunedDataFilesAreNamedInTheOutput(): void
    {
        $root = $this->tempCopy('sample-app');
        $runner = CannedComposer::runner(['acme/lib/data/tlds.txt' => "com\n"]);
        $tester = new CommandTester(new BuildCommand(new Builder(runner: $runner)));
        $exit = $tester->execute([
            '--root' => $root,
            '--out' => $this->freshDir(),
        ]);

        $display = $tester->getDisplay();
        self::assertSame(0, $exit);
        self::assertStringContainsString('data-looking files were pruned', $display);
        self::assertStringContainsString('vendor/acme/lib/data/tlds.txt', $display);
    }
}
