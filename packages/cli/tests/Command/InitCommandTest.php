<?php

declare(strict_types=1);

namespace Atoms\Cli\Tests\Command;

use Atoms\Cli\Command\InitCommand;
use Atoms\Cli\Release\RuntimeVersion;
use Atoms\Cli\Tests\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class InitCommandTest extends TestCase
{
    public function testWritesConfigThenRefuses(): void
    {
        $dir = $this->freshDir();
        $tester = new CommandTester(new InitCommand());

        $exit = $tester->execute(['--root' => $dir, '--project' => 'acme']);
        self::assertSame(0, $exit);
        self::assertFileExists($dir . '/atoms.json');
        self::assertFileExists($dir . '/atoms-composer.json');
        self::assertSame("/.atoms/\n", file_get_contents($dir . '/.gitignore'));

        $config = json_decode((string) file_get_contents($dir . '/atoms.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('acme', $config['project']);
        self::assertSame('app/Atoms', $config['paths']['atoms']);
        self::assertStringContainsString(
            RuntimeVersion::scaffoldCommand(),
            $tester->getDisplay(),
        );
        self::assertStringContainsString('cd atoms-worker && npm ci', $tester->getDisplay());
        self::assertStringContainsString('atoms-runtime-cloudflare upgrade', $tester->getDisplay());
        // The Worker directory is committed beside atoms.json; atoms.json no
        // longer names it, and a generated file must not carry the refused key.
        foreach ($config['environments'] as $environment) {
            self::assertArrayNotHasKey('worker_dir', $environment);
        }

        // Second run must refuse rather than overwrite.
        $second = $tester->execute(['--root' => $dir]);
        self::assertSame(1, $second);
        self::assertStringContainsString('already exists', $tester->getDisplay());
    }

    public function testPreservesExistingGitignoreAndAddsAtomsOnce(): void
    {
        $dir = $this->freshDir();
        file_put_contents($dir . '/.gitignore', "/vendor/\n");
        $tester = new CommandTester(new InitCommand());

        self::assertSame(0, $tester->execute(['--root' => $dir]));
        self::assertSame("/vendor/\n/.atoms/\n", file_get_contents($dir . '/.gitignore'));
    }
}
