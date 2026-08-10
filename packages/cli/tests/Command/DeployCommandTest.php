<?php

declare(strict_types=1);

namespace Atoms\Cli\Tests\Command;

use Atoms\Cli\Cloudflare\BundleStager;
use Atoms\Cli\Command\DeployCommand;
use Atoms\Cli\Process\ProcessResult;
use Atoms\Cli\Tests\Support\FakeProcessRunner;
use Atoms\Cli\Tests\Support\FakeWrangler;
use Atoms\Cli\Tests\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class DeployCommandTest extends TestCase
{
    private function bundleFile(): string
    {
        $path = $this->freshDir() . '/bundle.tar.gz';
        file_put_contents($path, (string) gzencode('dummy'));
        file_put_contents(\dirname($path) . '/manifest.json', '{"schema":1,"atoms":[]}');

        return $path;
    }

    /**
     * A Worker project that looks real enough for CloudflareTarget's checks: a
     * wrangler config and the staging script. Nothing is executed — the process
     * runner is faked — so the files may be empty.
     */
    private function workerDir(): string
    {
        $dir = $this->freshDir();
        file_put_contents($dir . '/wrangler.jsonc', '{}');
        mkdir($dir . '/scripts', 0777, true);
        file_put_contents($dir . '/' . BundleStager::SCRIPT, '');

        return $dir;
    }

    private function stager(?FakeProcessRunner $runner = null): BundleStager
    {
        return new BundleStager($runner ?? new FakeProcessRunner());
    }

    protected function setUp(): void
    {
        parent::setUp();
        // These must not leak in from the ambient environment: several tests
        // assert on their absence.
        // There is no --api-token option: a credential in argv is visible to
        // every process on the machine. The environment is the only inlet.
        putenv('CLOUDFLARE_API_TOKEN=cf-token');
        putenv('CLOUDFLARE_ACCOUNT_ID');
    }

    protected function tearDown(): void
    {
        putenv('CLOUDFLARE_API_TOKEN');
        parent::tearDown();
    }

    public function testSuccessfulDeployStagesThenRunsWrangler(): void
    {
        $runner = new FakeProcessRunner();
        $wrangler = new FakeWrangler();
        $tester = new CommandTester(new DeployCommand($wrangler, $this->stager($runner)));

        $exit = $tester->execute([
            '--root' => $this->fixtureDir('sample-app'),
            '--env' => 'production',
            '--worker-dir' => $this->workerDir(),
            '--bundle' => $this->bundleFile(),
        ]);

        self::assertSame(0, $exit, $tester->getDisplay());
        self::assertStringContainsString('Deployed acme-games to production', $tester->getDisplay());

        // Staged before deployed, and staged by the Worker tree's own script.
        self::assertCount(1, $runner->runs);
        self::assertSame('/usr/bin/node', $runner->runs[0]['command'][0]);
        self::assertStringEndsWith(BundleStager::SCRIPT, $runner->runs[0]['command'][1]);
        self::assertSame(BundleStager::OUTPUT, $runner->runs[0]['command'][4]);

        $deploy = $wrangler->lastCall('deploy');
        self::assertNotNull($deploy);
        self::assertSame('acme-games', $deploy['target']->workerName);
    }

    /**
     * A successful deploy is not a deploy that is in force. This caveat is the
     * whole fix for the convergence finding — measured on a real account,
     * /healthz reached the new Worker while the first invocation still 404'd —
     * so dropping or rewording it away must fail the suite rather than pass it.
     */
    public function testSuccessSaysThatPropagationIsNotInstant(): void
    {
        $tester = new CommandTester(new DeployCommand(new FakeWrangler(), $this->stager()));

        $tester->execute([
            '--root' => $this->fixtureDir('sample-app'),
            '--env' => 'production',
            '--worker-dir' => $this->workerDir(),
            '--bundle' => $this->bundleFile(),
        ]);

        $display = $tester->getDisplay();
        self::assertStringContainsString('propagates', $display);
        self::assertStringContainsString('previous', $display, 'the warning must name what is still being served');
        self::assertStringContainsString('atoms status', $display, 'and point at how to check');
    }

    public function testCredentialsReachOnlyTheChildEnvironment(): void
    {
        putenv('CLOUDFLARE_API_TOKEN=cf-secret-token');
        $wrangler = new FakeWrangler();
        $tester = new CommandTester(new DeployCommand($wrangler, $this->stager()));

        $tester->execute([
            '--root' => $this->fixtureDir('sample-app'),
            '--env' => 'production',
            '--worker-dir' => $this->workerDir(),
            '--bundle' => $this->bundleFile(),
        ]);

        $deploy = $wrangler->lastCall('deploy');
        self::assertNotNull($deploy);
        self::assertSame(
            ['CLOUDFLARE_API_TOKEN' => 'cf-secret-token', 'CLOUDFLARE_ACCOUNT_ID' => 'cf-account-1234'],
            $deploy['target']->credentialEnv(),
        );
        self::assertStringNotContainsString(
            'cf-secret-token',
            $tester->getDisplay(),
            'the API token must never be echoed',
        );
    }

    public function testWranglerFailureMapsToE074AndShowsWranglerOutput(): void
    {
        $wrangler = new FakeWrangler();
        $wrangler->deployResult = FakeWrangler::failed(
            ['deploy'],
            "✘ [ERROR] Authentication error [code: 10000]\n",
        );
        $tester = new CommandTester(new DeployCommand($wrangler, $this->stager()));

        $exit = $tester->execute([
            '--root' => $this->fixtureDir('sample-app'),
            '--env' => 'production',
            '--worker-dir' => $this->workerDir(),
            '--bundle' => $this->bundleFile(),
        ]);

        $display = $tester->getDisplay();
        self::assertSame(1, $exit);
        self::assertStringContainsString('ATOMS-E074', $display);
        self::assertStringContainsString('Authentication error', $display, "wrangler's own diagnosis must survive");
    }

    public function testStagingFailureMapsToE074AndNeverDeploys(): void
    {
        $runner = new FakeProcessRunner(new ProcessResult(1, '', 'Error: atom Counter declares /app/Counter.php, which is not in the bundle'));
        $wrangler = new FakeWrangler();
        $tester = new CommandTester(new DeployCommand($wrangler, $this->stager($runner)));

        $exit = $tester->execute([
            '--root' => $this->fixtureDir('sample-app'),
            '--env' => 'production',
            '--worker-dir' => $this->workerDir(),
            '--bundle' => $this->bundleFile(),
        ]);

        $display = $tester->getDisplay();
        self::assertSame(1, $exit);
        self::assertStringContainsString('ATOMS-E074', $display);
        self::assertStringContainsString('not in the bundle', $display);
        self::assertSame([], $wrangler->calls, 'a bundle that will not stage must never be deployed');
    }

    public function testMissingApiTokenMapsToE072(): void
    {
        putenv('CLOUDFLARE_API_TOKEN');
        $wrangler = new FakeWrangler();
        $tester = new CommandTester(new DeployCommand($wrangler, $this->stager()));

        $exit = $tester->execute([
            '--root' => $this->fixtureDir('sample-app'),
            '--env' => 'production',
            '--worker-dir' => $this->workerDir(),
        ]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('ATOMS-E072', $tester->getDisplay());
        self::assertSame([], $wrangler->calls, 'nothing should run without credentials');
    }

    public function testMissingAccountIdMapsToE075(): void
    {
        $wrangler = new FakeWrangler();
        $tester = new CommandTester(new DeployCommand($wrangler, $this->stager()));

        // A project whose atoms.json carries no account_id, with none in the
        // environment either.
        $root = $this->tempCopy('sample-app');
        $config = json_decode((string) file_get_contents($root . '/atoms.json'), true);
        unset($config['environments']['production']['account_id']);
        file_put_contents($root . '/atoms.json', json_encode($config, JSON_THROW_ON_ERROR));

        $exit = $tester->execute([
            '--root' => $root,
            '--env' => 'production',
            '--worker-dir' => $this->workerDir(),
        ]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('ATOMS-E075', $tester->getDisplay());
        self::assertSame([], $wrangler->calls);
    }

    public function testUnusableWorkerDirectoryMapsToE076(): void
    {
        $wrangler = new FakeWrangler();
        $tester = new CommandTester(new DeployCommand($wrangler, $this->stager()));

        $exit = $tester->execute([
            '--root' => $this->fixtureDir('sample-app'),
            '--env' => 'production',
            // Exists, but holds no wrangler config: the "you forgot npm ci /
            // pointed at the wrong tree" case.
            '--worker-dir' => $this->freshDir(),
            '--bundle' => $this->bundleFile(),
        ]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('ATOMS-E076', $tester->getDisplay());
        self::assertSame([], $wrangler->calls);
    }
}
