<?php

declare(strict_types=1);

namespace Atoms\Cli\Tests\Command;

use Atoms\Cli\Cloudflare\RuntimeStamp;
use Atoms\Cli\Command\DevCommand;
use Atoms\Cli\Process\ProcessResult;
use Atoms\Cli\Release\RuntimeVersion;
use Atoms\Cli\Tests\Support\FakeProcessRunner;
use Atoms\Cli\Tests\Support\FakeWrangler;
use Atoms\Cli\Tests\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `atoms dev` guarantees a per-machine ATOMS_SHARED_SECRET in the Worker
 * project's .dev.vars before starting wrangler dev (docs/shared-secret.md).
 *
 * It must also agree with `atoms deploy` on what atoms.json's
 * `debug_endpoints` means: one declaration, forwarded to Wrangler as a
 * `--var` on both paths. The deploy half lives in {@see DeployCommandTest}.
 */
final class DevCommandTest extends TestCase
{
    /**
     * A Worker project that looks real enough for CloudflareTarget's checks: a
     * wrangler config and the release stamp. Nothing is executed — Wrangler
     * itself is faked.
     */
    private function workerDir(): string
    {
        $dir = $this->freshDir();
        file_put_contents($dir . '/wrangler.jsonc', json_encode(['name' => 'w'], JSON_THROW_ON_ERROR));
        self::stamp($dir);

        return $dir;
    }

    /**
     * The stamp `atoms-runtime-cloudflare init` writes; deploy and dev refuse
     * a directory whose stamp names another release (ATOMS-E108), so a test
     * Worker directory carries the current one.
     */
    private static function stamp(string $dir, string $version = RuntimeVersion::VERSION): void
    {
        file_put_contents(
            $dir . '/' . RuntimeStamp::FILE,
            json_encode(['package' => RuntimeVersion::PACKAGE, 'version' => $version], JSON_THROW_ON_ERROR),
        );
    }

    private function execute(string $workerDir, FakeWrangler $wrangler, ?FakeProcessRunner $runner = null): CommandTester
    {
        $tester = new CommandTester(new DevCommand($wrangler, processRunner: $runner ?? new FakeProcessRunner()));
        $tester->execute([
            '--root' => $this->fixtureDir('sample-app'),
            '--env' => 'production',
            '--worker-dir' => $workerDir,
            '--no-build' => true,
        ]);

        return $tester;
    }

    public function testGeneratesADevSecretWhenDevVarsIsAbsent(): void
    {
        $dir = $this->workerDir();
        $wrangler = new FakeWrangler();

        $tester = $this->execute($dir, $wrangler);

        $path = $dir . '/.dev.vars';
        self::assertFileExists($path);
        $contents = (string) file_get_contents($path);
        self::assertMatchesRegularExpression('/^ATOMS_SHARED_SECRET=([A-Za-z0-9+\/]{43}=)$/m', $contents);

        preg_match('/^ATOMS_SHARED_SECRET=([A-Za-z0-9+\/]{43}=)$/m', $contents, $m);
        $decoded = base64_decode($m[1], true);
        self::assertNotFalse($decoded);
        self::assertSame(32, \strlen($decoded));

        self::assertStringContainsString('Generated a per-machine dev secret at ' . $path, $tester->getDisplay());
        self::assertStringNotContainsString($m[1], $tester->getDisplay(), 'the generated secret must never be printed');

        $dev = $wrangler->lastCall('dev');
        self::assertNotNull($dev, 'wrangler dev must still run once the secret is guaranteed');
        self::assertArrayNotHasKey(
            'ATOMS_SHARED_SECRET',
            $dev['args']['vars'],
            'the secret must reach the Worker only via .dev.vars, never as a --var/argv value',
        );
    }

    public function testNewDevVarsFileIsCreatedWithMode0600(): void
    {
        $dir = $this->workerDir();
        $this->execute($dir, new FakeWrangler());

        $mode = fileperms($dir . '/.dev.vars') & 0777;
        self::assertSame(0600, $mode);
    }

    public function testLeavesAnExistingDevVarsSecretUntouched(): void
    {
        $dir = $this->workerDir();
        $existing = base64_encode(str_repeat("\x11", 32));
        file_put_contents($dir . '/.dev.vars', "ATOMS_CALLBACK_URL=http://example.com\nATOMS_SHARED_SECRET={$existing}\n");

        $tester = $this->execute($dir, new FakeWrangler());

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $contents = (string) file_get_contents($dir . '/.dev.vars');
        self::assertSame(
            "ATOMS_CALLBACK_URL=http://example.com\nATOMS_SHARED_SECRET={$existing}\n",
            $contents,
            'an existing secret line must be left byte-for-byte untouched',
        );
        self::assertStringContainsString('Using the dev secret at ' . $dir . '/.dev.vars', $tester->getDisplay());
    }

    /**
     * Outside a git work tree there is nothing a commit could expose, so
     * generation proceeds without the gitignore gate. FakeProcessRunner's
     * default result (exit 0, empty stdout) already models this: `rev-parse
     * --is-inside-work-tree` returning anything other than exactly "true"
     * means "not a work tree" here.
     */
    public function testProceedsOutsideAGitWorkTree(): void
    {
        $dir = $this->workerDir();
        $tester = $this->execute($dir, new FakeWrangler());

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertFileExists($dir . '/.dev.vars');
    }

    /**
     * A `git` that cannot be run at all (missing binary, exits non-zero) is
     * treated the same as "not a work tree" — there is no repository this
     * process can prove would ever commit the file.
     */
    public function testProceedsWhenGitItselfFails(): void
    {
        $dir = $this->workerDir();
        $runner = new FakeProcessRunner(new ProcessResult(127, '', 'git: command not found'));

        $tester = $this->execute($dir, new FakeWrangler(), $runner);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertFileExists($dir . '/.dev.vars');
    }

    public function testProceedsWhenDevVarsIsGitignored(): void
    {
        $dir = $this->workerDir();
        $runner = new FakeProcessRunner();
        // Both `git rev-parse --is-inside-work-tree` and `git check-ignore -q`
        // need exit 0 here; check-ignore ignores stdout, and "true\n" trims to
        // exactly what rev-parse must answer — one canned result satisfies both.
        $runner->result = new ProcessResult(0, "true\n", '');

        $tester = $this->execute($dir, new FakeWrangler(), $runner);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertFileExists($dir . '/.dev.vars');
    }

    public function testRefusesToGenerateWhenDevVarsIsNotGitignored(): void
    {
        $dir = $this->workerDir();
        $runner = new FakeProcessRunner();
        $runner->resultFor = static function (array $command) {
            if (\in_array('rev-parse', $command, true)) {
                return new ProcessResult(0, "true\n", '');
            }
            if (\in_array('check-ignore', $command, true)) {
                // Exit 1: git check-ignore says the path is NOT ignored.
                return new ProcessResult(1, '', '');
            }

            return null;
        };

        $tester = $this->execute($dir, new FakeWrangler(), $runner);

        self::assertSame(1, $tester->getStatusCode());
        self::assertFileDoesNotExist($dir . '/.dev.vars', 'a secret must never be written before the gate passes');
        $display = $tester->getDisplay();
        self::assertStringContainsString('ATOMS-E105', $display);
        self::assertStringContainsString('.gitignore', $display);
    }

    public function testDebugEndpointsDefaultOffInDevToo(): void
    {
        $wrangler = new FakeWrangler();
        $tester = $this->execute($this->workerDir(), $wrangler);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $dev = $wrangler->lastCall('dev');
        self::assertNotNull($dev);
        self::assertArrayNotHasKey('ATOMS_DEBUG_ENDPOINTS', $dev['args']['vars']);
    }

    /**
     * `dev` runs the committed runtime locally, so it makes the same skew
     * check deploy does: a directory from another release is refused before
     * a dev secret is provisioned or wrangler dev starts.
     */
    public function testAWorkerDirectoryFromAnotherReleaseIsE108BeforeWranglerDevStarts(): void
    {
        $dir = $this->workerDir();
        self::stamp($dir, '0.0.1-other');
        $wrangler = new FakeWrangler();

        $tester = $this->execute($dir, $wrangler);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('ATOMS-E108', $tester->getDisplay());
        self::assertStringContainsString('atoms-runtime-cloudflare upgrade', $tester->getDisplay());
        self::assertNull($wrangler->lastCall('dev'));
        self::assertFileDoesNotExist($dir . '/.dev.vars');
    }

    /**
     * The one supported, re-scaffold-proof way to turn the Worker's /debug
     * routes on — atoms.json's per-environment `debug_endpoints` — must reach
     * `wrangler dev` as a --var, without displacing the callback var.
     */
    public function testDebugEndpointsInAtomsJsonReachWranglerDevAlongsideTheCallbackVar(): void
    {
        $root = $this->tempCopy('sample-app');
        $config = json_decode((string) file_get_contents($root . '/atoms.json'), true);
        $config['environments']['staging']['debug_endpoints'] = true;
        file_put_contents($root . '/atoms.json', json_encode($config, JSON_THROW_ON_ERROR));

        $wrangler = new FakeWrangler();
        $tester = new CommandTester(new DevCommand($wrangler, processRunner: new FakeProcessRunner()));
        $exit = $tester->execute([
            '--root' => $root,
            '--env' => 'staging',
            '--worker-dir' => $this->workerDir(),
            '--no-build' => true,
        ]);

        self::assertSame(0, $exit, $tester->getDisplay());
        $dev = $wrangler->lastCall('dev');
        self::assertNotNull($dev);
        self::assertSame(
            [
                'ATOMS_DEBUG_ENDPOINTS' => '1',
                // The fixture's atoms.json declares a staging callback_url, and
                // enabling debug endpoints must not displace it.
                DevCommand::CALLBACK_VAR => 'https://staging.acme.example.com',
            ],
            $dev['args']['vars'],
        );
        // Enabling a debug surface must be visible at startup.
        self::assertStringContainsString('ATOMS_DEBUG_ENDPOINTS=1', $tester->getDisplay());
    }
}
