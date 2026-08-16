<?php

declare(strict_types=1);

namespace Atoms\Cli\Tests\Command;

use Atoms\Cli\Cloudflare\DevVars;
use Atoms\Cli\Command\DevCommand;
use Atoms\Cli\Process\ProcessResult;
use Atoms\Cli\Tests\Support\FakeProcessRunner;
use Atoms\Cli\Tests\Support\FakeWrangler;
use Atoms\Cli\Tests\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The app's dotenv file is the source of truth for ATOMS_SHARED_SECRET, and
 * `.dev.vars` is a generated projection of it (docs/shared-secret.md).
 *
 * {@see DevCommandTest} covers the no-dotenv project, where `.dev.vars` is
 * both source and sink.
 */
final class DevCommandEnvSyncTest extends TestCase
{
    private function workerDir(): string
    {
        $dir = $this->freshDir();
        file_put_contents($dir . '/wrangler.jsonc', json_encode(['name' => 'w'], JSON_THROW_ON_ERROR));

        return $dir;
    }

    /**
     * A git posture. Every path is gitignored except the basenames in
     * $notIgnored — a Symfony-style committed `.env`, say.
     *
     * @param list<string> $notIgnored
     */
    private function git(array $notIgnored = []): FakeProcessRunner
    {
        $runner = new FakeProcessRunner();
        $runner->resultFor = static function (array $command) use ($notIgnored) {
            if (\in_array('rev-parse', $command, true)) {
                return new ProcessResult(0, "true\n", '');
            }
            if (\in_array('check-ignore', $command, true)) {
                $path = end($command);

                return \in_array($path, $notIgnored, true)
                    ? new ProcessResult(1, '', '')
                    : new ProcessResult(0, '', '');
            }

            return null;
        };

        return $runner;
    }

    /**
     * @param list<string> $extraArgs
     */
    private function execute(
        string $root,
        string $workerDir,
        ?FakeProcessRunner $runner = null,
        array $extraArgs = [],
    ): CommandTester {
        $tester = new CommandTester(new DevCommand(new FakeWrangler(), processRunner: $runner ?? $this->git()));
        $tester->execute([
            '--root' => $root,
            '--env' => 'production',
            '--worker-dir' => $workerDir,
            '--no-build' => true,
            ...array_fill_keys($extraArgs, true),
        ]);

        return $tester;
    }

    private function secretIn(string $path): ?string
    {
        return \Atoms\Cli\Config\EnvFile::read($path, DevVars::SECRET_KEY);
    }

    public function testGeneratesIntoDotEnvAndProjectsIntoDevVars(): void
    {
        $root = $this->tempCopy('sample-app');
        file_put_contents($root . '/.env', "APP_NAME=Acme\nAPP_DEBUG=true\n");
        $worker = $this->workerDir();

        $tester = $this->execute($root, $worker);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $secret = $this->secretIn($worker . '/.dev.vars');
        self::assertNotNull($secret);
        self::assertSame($secret, $this->secretIn($root . '/.env'), 'both halves must hold the identical value');

        $contents = (string) file_get_contents($root . '/.env');
        self::assertStringContainsString("APP_NAME=Acme\nAPP_DEBUG=true\n", $contents, 'existing keys survive');
        self::assertStringContainsString('Generated a per-machine dev secret in ' . $root . '/.env', $tester->getDisplay());
        self::assertStringNotContainsString($secret, $tester->getDisplay(), 'the secret must never be printed');
    }

    /**
     * Symfony commits `.env` and gitignores `.env.local`, so asking git picks
     * `.env.local` on its own — no framework detection needed.
     */
    public function testPrefersDotEnvLocalWhenDotEnvIsCommitted(): void
    {
        $root = $this->tempCopy('sample-app');
        file_put_contents($root . '/.env', "APP_ENV=dev\n");
        $worker = $this->workerDir();

        $tester = $this->execute($root, $worker, $this->git(notIgnored: ['.env']));

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame("APP_ENV=dev\n", (string) file_get_contents($root . '/.env'), 'a committed .env is untouched');
        self::assertSame(
            $this->secretIn($worker . '/.dev.vars'),
            $this->secretIn($root . '/.env.local'),
        );
        self::assertSame(0600, fileperms($root . '/.env.local') & 0777);
    }

    /**
     * An existing `.env.local` means the project uses the local-override
     * convention, so the secret belongs there even if `.env` is also safe.
     */
    public function testPrefersAnExistingDotEnvLocalOverDotEnv(): void
    {
        $root = $this->tempCopy('sample-app');
        file_put_contents($root . '/.env', "APP_ENV=dev\n");
        file_put_contents($root . '/.env.local', "APP_ENV=local\n");
        $worker = $this->workerDir();

        $this->execute($root, $worker);

        self::assertNull($this->secretIn($root . '/.env'));
        self::assertSame($this->secretIn($worker . '/.dev.vars'), $this->secretIn($root . '/.env.local'));
    }

    /** No dotenv file means no dotenv: never invent one, keep it in .dev.vars. */
    public function testKeepsTheSecretInDevVarsWhenTheProjectHasNoDotEnv(): void
    {
        $root = $this->tempCopy('sample-app');
        $worker = $this->workerDir();

        $tester = $this->execute($root, $worker);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertFileDoesNotExist($root . '/.env');
        self::assertFileDoesNotExist($root . '/.env.local');
        self::assertNotNull($this->secretIn($worker . '/.dev.vars'));
    }

    public function testSecondRunIsANoOp(): void
    {
        $root = $this->tempCopy('sample-app');
        file_put_contents($root . '/.env', "APP_NAME=Acme\n");
        $worker = $this->workerDir();

        $this->execute($root, $worker);
        $afterFirst = (string) file_get_contents($root . '/.env');

        $tester = $this->execute($root, $worker);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame($afterFirst, (string) file_get_contents($root . '/.env'), 'a second run must not append again');
        self::assertStringContainsString('Using the dev secret in ' . $root . '/.env', $tester->getDisplay());
    }

    /** An existing app secret is adopted, never regenerated or overwritten. */
    public function testAdoptsAnExistingAppSecret(): void
    {
        $root = $this->tempCopy('sample-app');
        $mine = base64_encode(str_repeat("\x22", 32));
        file_put_contents($root . '/.env', "APP_NAME=Acme\nATOMS_SHARED_SECRET={$mine}\n");
        $worker = $this->workerDir();

        $tester = $this->execute($root, $worker);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame("APP_NAME=Acme\nATOMS_SHARED_SECRET={$mine}\n", (string) file_get_contents($root . '/.env'));
        self::assertSame($mine, $this->secretIn($worker . '/.dev.vars'), 'the projection follows the app');
        self::assertStringContainsString('Using the dev secret in ' . $root . '/.env', $tester->getDisplay());
    }

    /** A stale .dev.vars is a stale projection, so it is simply rewritten. */
    public function testRewritesAStaleProjection(): void
    {
        $root = $this->tempCopy('sample-app');
        $mine = base64_encode(str_repeat("\x22", 32));
        $stale = base64_encode(str_repeat("\x33", 32));
        file_put_contents($root . '/.env', "ATOMS_SHARED_SECRET={$mine}\n");
        $worker = $this->workerDir();
        file_put_contents($worker . '/.dev.vars', "ATOMS_SHARED_SECRET={$stale}\n");

        $tester = $this->execute($root, $worker);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame($mine, $this->secretIn($worker . '/.dev.vars'));
        self::assertSame($mine, $this->secretIn($root . '/.env'), 'the app file is the source, never rewritten');
        self::assertStringContainsString('Projected into ' . $worker . '/.dev.vars', $tester->getDisplay());
    }

    /** Neither candidate safe: stop, and name the file to gitignore. */
    public function testRefusesWhenNoDotEnvFileIsGitSafe(): void
    {
        $root = $this->tempCopy('sample-app');
        file_put_contents($root . '/.env', "APP_ENV=dev\n");

        $tester = $this->execute($root, $this->workerDir(), $this->git(notIgnored: ['.env', '.env.local']));

        self::assertSame(1, $tester->getStatusCode());
        self::assertFileDoesNotExist($root . '/.env.local');
        $display = $tester->getDisplay();
        self::assertStringContainsString('ATOMS-E105', $display);
        self::assertStringContainsString('.gitignore', $display);
    }
}
