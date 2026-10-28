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
        // .env.atoms.<environment> is the per-target file the CLI reads below
        // whatever the caller supplied, so it is where a local Cloudflare
        // token or machine-specific callback URL goes — and it must never be
        // committed. Anything shared and non-secret belongs in atoms.json.
        self::assertSame("/.atoms/\n/.env.atoms.*\n", file_get_contents($dir . '/.gitignore'));

        $config = json_decode((string) file_get_contents($dir . '/atoms.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('acme', $config['project']);
        self::assertSame('app/Atoms', $config['paths']['atoms']);
        // No placeholder host. The file entry is the committed default for a
        // named deployment, so an example.com left in by accident would POST
        // signed callbacks — carrying method arguments — to a third party.
        // Empty means the file declares nothing; unless ATOMS_CALLBACK_URL or
        // --callback-url supplies one, deploy warns and app()/dispatch() raise
        // E080.
        self::assertArrayNotHasKey('callback_url', $config, 'callback_url is per-environment, not a parallel top-level map');
        self::assertSame('', $config['environments']['production']['callback_url']);
        self::assertSame('', $config['environments']['staging']['callback_url']);
        self::assertStringContainsString(
            RuntimeVersion::scaffoldCommand(),
            $tester->getDisplay(),
        );
        self::assertStringContainsString('cd atoms-worker && npm ci', $tester->getDisplay());
        self::assertStringContainsString('atoms-runtime-cloudflare upgrade', $tester->getDisplay());
        // The Worker directory is committed beside atoms.json, so an
        // environment holds exactly its own settings.
        // One block per environment, holding every setting that differs
        // between them — a second top-level map keyed by the same names is
        // what let a typo'd environment parse clean and silently supply
        // nothing.
        foreach ($config['environments'] as $environment) {
            self::assertSame(
                ['worker_name', 'account_id', 'debug_endpoints', 'callback_url'],
                array_keys($environment),
            );
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
        self::assertSame("/vendor/\n/.atoms/\n/.env.atoms.*\n", file_get_contents($dir . '/.gitignore'));
    }

    /**
     * Each entry is added only if it is missing, and independently of the
     * other: a project that already ignores one keeps its own spelling.
     */
    public function testAnEntryAlreadyPresentIsNotAddedAgain(): void
    {
        $dir = $this->freshDir();
        file_put_contents($dir . '/.gitignore', ".env.atoms.production\n.env.atoms.staging\n");
        $tester = new CommandTester(new InitCommand());

        self::assertSame(0, $tester->execute(['--root' => $dir]));
        self::assertSame(
            ".env.atoms.production\n.env.atoms.staging\n/.atoms/\n",
            file_get_contents($dir . '/.gitignore'),
        );
    }
}
