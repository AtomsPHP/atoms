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
        // No placeholder host. The file entry is the committed default for a
        // named deployment, so an example.com left in by accident would POST
        // signed callbacks — carrying method arguments — to a third party.
        // Empty means the file declares nothing; unless ATOMS_CALLBACK_URL or
        // --callback-url supplies one, deploy warns and app()/dispatch() raise
        // E080.
        self::assertSame(['production' => '', 'staging' => ''], $config['callback_url']);
        self::assertStringContainsString(
            RuntimeVersion::scaffoldCommand(),
            $tester->getDisplay(),
        );
        self::assertStringContainsString('cd atoms-worker && npm ci', $tester->getDisplay());
        self::assertStringContainsString('atoms-runtime-cloudflare upgrade', $tester->getDisplay());
        // The Worker directory is committed beside atoms.json, so an
        // environment holds exactly its own settings.
        foreach ($config['environments'] as $environment) {
            self::assertSame(['worker_name', 'account_id', 'debug_endpoints'], array_keys($environment));
            self::assertArrayNotHasKey('endpoint', $environment);
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
