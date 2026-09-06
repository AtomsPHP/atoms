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

    public function testBuildDoesNotResolveCallbackReferencesInAnyEnvironment(): void
    {
        $root = $this->tempCopy('sample-app');
        $config = json_decode((string) file_get_contents($root . '/atoms.json'), true, 512, JSON_THROW_ON_ERROR);
        $config['callback_url']['production'] = '${UNSET_BUILD_CALLBACK}';
        $config['callback_url']['staging'] = '${ALSO_UNSET_BUILD_CALLBACK}';
        file_put_contents($root . '/atoms.json', json_encode($config, JSON_THROW_ON_ERROR));
        putenv('UNSET_BUILD_CALLBACK');
        putenv('ALSO_UNSET_BUILD_CALLBACK');

        $tester = new CommandTester(new BuildCommand(new Builder(runner: CannedComposer::runner())));
        $exit = $tester->execute([
            '--root' => $root,
            '--out' => $this->freshDir(),
        ]);

        self::assertSame(0, $exit, $tester->getDisplay());
        self::assertStringContainsString('content hash', $tester->getDisplay());
    }
}
