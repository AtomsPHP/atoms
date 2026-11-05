<?php

declare(strict_types=1);

namespace Atoms\Cli\Tests\Command;

use Atoms\Cli\Application;
use Atoms\Cli\Command\PhpstanInstallCommand;
use Atoms\Cli\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

final class PhpstanInstallCommandTest extends TestCase
{
    public function testPreservesApplicationConfigsAndUserEditsOnRepeatedRuns(): void
    {
        $dir = $this->project();
        foreach (['phpstan.neon', 'phpstan.neon.dist', 'phpstan.dist.neon'] as $file) {
            file_put_contents($dir . '/' . $file, "# Application settings\nparameters:\n    level: 5\n");
        }
        $tester = new CommandTester((new Application())->find('phpstan:install'));
        self::assertSame(0, $tester->execute(['--root' => $dir]));
        self::assertStringContainsString('composer require --dev atoms/phpstan-rules', $tester->getDisplay());
        self::assertStringContainsString('vendor/bin/phpstan analyse --configuration=phpstan.atoms.neon', $tester->getDisplay());

        $edited = (string) file_get_contents($dir . '/phpstan.atoms.neon') . "# User settings\n";
        file_put_contents($dir . '/phpstan.atoms.neon', $edited);
        self::assertSame(0, $tester->execute(['--root' => $dir]));
        self::assertSame($edited, file_get_contents($dir . '/phpstan.atoms.neon'));
        foreach (['phpstan.neon', 'phpstan.neon.dist', 'phpstan.dist.neon'] as $file) {
            self::assertSame("# Application settings\nparameters:\n    level: 5\n", file_get_contents($dir . '/' . $file));
        }
    }

    public function testInvalidAtomsConfigFailsWithoutWritingPhpstanConfig(): void
    {
        $dir = $this->freshDir();
        file_put_contents($dir . '/atoms.json', '{');
        $tester = new CommandTester(new PhpstanInstallCommand());

        self::assertSame(1, $tester->execute(['--root' => $dir]));
        self::assertStringContainsString('ATOMS-E070', $tester->getDisplay());
        self::assertFileDoesNotExist($dir . '/phpstan.atoms.neon');
    }

    public function testBlockedDestinationReportsCatalogError(): void
    {
        $dir = $this->project();
        mkdir($dir . '/phpstan.atoms.neon');
        $tester = new CommandTester(new PhpstanInstallCommand());

        self::assertSame(1, $tester->execute(['--root' => $dir]));
        self::assertStringContainsString('ATOMS-E113', $tester->getDisplay());
        self::assertStringContainsString('Fix: Check that the project directory is writable', $tester->getDisplay());
        self::assertDirectoryExists($dir . '/phpstan.atoms.neon');
    }

    /** @return iterable<string, array{string, string}> */
    public static function sourcePaths(): iterable
    {
        yield 'default' => ['app/Atoms', 'app/Atoms/Shared'];
        yield 'separate shared path' => ['src/Rooms', 'src/Shared'];
        yield 'NEON quoting and parameter escaping' => ['src/Rooms #1 %room%', "src/Shared 'types'"];
    }

    #[DataProvider('sourcePaths')]
    public function testGeneratedConfigurationRunsPhpstanAndEnforcesAtomsRules(string $atoms, string $shared): void
    {
        $dir = $this->project($atoms, $shared);
        mkdir($dir . '/' . $atoms, 0777, true);
        mkdir($dir . '/' . $shared, 0777, true);
        $atomFile = $dir . '/' . $atoms . '/Room.php';
        file_put_contents($atomFile, <<<'PHP'
        <?php
        namespace InstallFixture;
        /** @extends \Atoms\Atom<\Atoms\AtomMethods> */
        final class Room extends \Atoms\Atom
        {
            public function count(): int { return 1; }
        }
        PHP);
        $root = dirname(__DIR__, 4);
        symlink($root . '/vendor', $dir . '/vendor');

        try {
            $tester = new CommandTester(new PhpstanInstallCommand());
            self::assertSame(0, $tester->execute(['--root' => $dir]));
            self::assertStringNotContainsString('composer require', $tester->getDisplay());
            $run = static function () use ($dir, $root): Process {
                $process = new Process([
                    PHP_BINARY, $root . '/vendor/phpstan/phpstan/phpstan', 'analyse',
                    '--configuration=' . $dir . '/phpstan.atoms.neon', '--no-progress',
                    '--error-format=raw', '--debug',
                ], $dir);
                $process->run();

                return $process;
            };
            $valid = $run();
            self::assertSame(0, $valid->getExitCode(), $valid->getOutput() . $valid->getErrorOutput());

            file_put_contents($atomFile, str_replace('return 1;', 'sleep(1); return 1;', (string) file_get_contents($atomFile)));
            // A Shared class outside the Atom path must also be analysed.
            file_put_contents($dir . '/' . $shared . '/Bad.php', <<<'PHP'
            <?php
            namespace InstallFixture;
            final class Bad
            {
                public function value(): string { return 123; }
            }
            PHP);
            $invalid = $run();
            self::assertSame(1, $invalid->getExitCode(), $invalid->getOutput() . $invalid->getErrorOutput());
            self::assertStringContainsString('ATOMS-E101', $invalid->getOutput());
            self::assertStringContainsString('/Bad.php', $invalid->getOutput());
        } finally {
            unlink($dir . '/vendor');
        }
    }

    private function project(string $atoms = 'app/Atoms', string $shared = 'app/Atoms/Shared'): string
    {
        $dir = $this->freshDir();
        file_put_contents($dir . '/atoms.json', json_encode([
            'project' => 'phpstan-install',
            'paths' => ['atoms' => $atoms, 'shared' => $shared],
        ], JSON_THROW_ON_ERROR));

        return $dir;
    }
}
