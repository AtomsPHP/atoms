<?php

declare(strict_types=1);

namespace Atoms\Cli\Tests\Command;

use Atoms\Cli\Cloudflare\BundleStager;
use Atoms\Cli\Cloudflare\RuntimeStamp;
use Atoms\Cli\Command\DeployCommand;
use Atoms\Cli\Process\ProcessResult;
use Atoms\Cli\Release\RuntimeVersion;
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
     * wrangler config, the staging script, and the release stamp. Nothing is
     * executed — the process runner is faked — so the files may be empty.
     */
    private function workerDir(): string
    {
        $dir = $this->freshDir();
        file_put_contents($dir . '/wrangler.jsonc', '{}');
        mkdir($dir . '/scripts', 0777, true);
        file_put_contents($dir . '/' . BundleStager::SCRIPT, '');
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
        // The fixture does not enable debug endpoints, so no --var overrides
        // ride along: what ships is exactly what the Worker config declares.
        self::assertSame([], $deploy['args']['vars']);
    }

    /**
     * The one supported way to turn the Worker's /debug routes on for ONE
     * environment: atoms.json's per-environment `debug_endpoints`, forwarded
     * as a Wrangler `--var`. The committed wrangler.jsonc is shared by every
     * environment, so a var living there would enable it everywhere.
     */
    public function testDebugEndpointsInAtomsJsonAreForwardedAsAVar(): void
    {
        $root = $this->tempCopy('sample-app');
        $config = json_decode((string) file_get_contents($root . '/atoms.json'), true);
        $config['environments']['production']['debug_endpoints'] = true;
        file_put_contents($root . '/atoms.json', json_encode($config, JSON_THROW_ON_ERROR));

        $wrangler = new FakeWrangler();
        $tester = new CommandTester(new DeployCommand($wrangler, $this->stager()));

        $exit = $tester->execute([
            '--root' => $root,
            '--env' => 'production',
            '--worker-dir' => $this->workerDir(),
            '--bundle' => $this->bundleFile(),
        ]);

        self::assertSame(0, $exit, $tester->getDisplay());
        $deploy = $wrangler->lastCall('deploy');
        self::assertNotNull($deploy);
        self::assertSame(['ATOMS_DEBUG_ENDPOINTS' => '1'], $deploy['args']['vars']);
        // Enabling a debug surface must be visible in the deploy log.
        self::assertStringContainsString('ATOMS_DEBUG_ENDPOINTS=1', $tester->getDisplay());
    }

    /**
     * The Worker directory is committed and co-versioned with the CLI, so a
     * CLI that moved on without it must refuse before anything is built or
     * shipped — naming both releases and the exact upgrade command.
     */
    public function testAWorkerDirectoryFromAnotherReleaseIsE108BeforeAnythingIsStagedOrDeployed(): void
    {
        $dir = $this->workerDir();
        self::stamp($dir, '0.0.1-other');
        $runner = new FakeProcessRunner();
        $wrangler = new FakeWrangler();
        $tester = new CommandTester(new DeployCommand($wrangler, $this->stager($runner)));

        $exit = $tester->execute([
            '--root' => $this->fixtureDir('sample-app'),
            '--env' => 'production',
            '--worker-dir' => $dir,
            '--bundle' => $this->bundleFile(),
        ]);

        self::assertSame(1, $exit);
        $display = $tester->getDisplay();
        self::assertStringContainsString('ATOMS-E108', $display);
        self::assertStringContainsString('0.0.1-other', $display);
        self::assertStringContainsString(RuntimeVersion::VERSION, $display);
        self::assertStringContainsString('atoms-runtime-cloudflare upgrade', $display);
        self::assertSame([], $runner->runs, 'nothing may be staged for a mismatched runtime');
        self::assertNull($wrangler->lastCall('deploy'), 'nothing may be deployed for a mismatched runtime');
    }

    /**
     * A directory with no stamp at all was scaffolded before stamps existed:
     * the same finding, with the version reported as unknown rather than
     * guessed.
     */
    public function testAnUnstampedWorkerDirectoryIsE108Too(): void
    {
        $dir = $this->workerDir();
        unlink($dir . '/' . RuntimeStamp::FILE);
        $wrangler = new FakeWrangler();
        $tester = new CommandTester(new DeployCommand($wrangler, $this->stager()));

        $exit = $tester->execute([
            '--root' => $this->fixtureDir('sample-app'),
            '--env' => 'production',
            '--worker-dir' => $dir,
            '--bundle' => $this->bundleFile(),
        ]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('ATOMS-E108', $tester->getDisplay());
        self::assertStringContainsString('unknown release', $tester->getDisplay());
        self::assertNull($wrangler->lastCall('deploy'));
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
        // A Cloudflare rejection that is not about credentials: those are
        // ATOMS-E072 now, and this test is about everything else.
        $wrangler->deployResult = FakeWrangler::failed(
            ['deploy'],
            "✘ [ERROR] A request to the Cloudflare API failed.\n  Script size too large [code: 10027]\n",
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
        self::assertStringContainsString('Script size too large', $display, "wrangler's own diagnosis must survive");
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

    public function testUnsupportedCoreStagingFailureMapsToE043AndNeverDeploys(): void
    {
        $runner = new FakeProcessRunner(new ProcessResult(
            1,
            '',
            'Error: ATOMS-E043: Bundle was built against atoms/core 0.2.0, but this runtime supports ^0.1.',
        ));
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
        self::assertStringContainsString('ATOMS-E043', $display);
        self::assertStringContainsString('0.2.0', $display);
        self::assertStringContainsString('^0.1', $display);
        self::assertStringNotContainsString('ATOMS-E074', $display);
        self::assertSame([], $wrangler->calls);
    }

    public function testMissingApiTokenDefersToWranglersOwnLoginSession(): void
    {
        putenv('CLOUDFLARE_API_TOKEN');
        $wrangler = new FakeWrangler();
        $tester = new CommandTester(new DeployCommand($wrangler, $this->stager()));

        $exit = $tester->execute([
            '--root' => $this->fixtureDir('sample-app'),
            '--env' => 'production',
            '--worker-dir' => $this->workerDir(),
            '--bundle' => $this->bundleFile(),
        ]);

        self::assertSame(0, $exit, $tester->getDisplay());
        self::assertStringNotContainsString('ATOMS-E072', $tester->getDisplay());

        $call = $wrangler->lastCall('deploy');
        self::assertNotNull($call, 'a token-less deploy must still reach Wrangler');
        // Nothing injected: the absence is what lets Wrangler use the OAuth
        // session `wrangler login` maintains, which Atoms never sees.
        self::assertArrayNotHasKey('CLOUDFLARE_API_TOKEN', $call['target']->credentialEnv());
    }

    public function testWranglerHavingNoCredentialsEitherMapsToE072(): void
    {
        putenv('CLOUDFLARE_API_TOKEN');
        $wrangler = new FakeWrangler();
        $wrangler->deployResult = FakeWrangler::failed(
            ['deploy'],
            "✘ [ERROR] In a non-interactive environment, it's necessary to set a CLOUDFLARE_API_TOKEN "
            . "environment variable for wrangler to work.\n",
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
        self::assertStringContainsString('ATOMS-E072', $display);
        self::assertStringNotContainsString('ATOMS-E074', $display);
        // Wrangler's own diagnosis is printed, not summarised away.
        self::assertStringContainsString('non-interactive environment', $display);
    }

    public function testMissingAccountIdAlsoDefersToWrangler(): void
    {
        $wrangler = new FakeWrangler();
        $tester = new CommandTester(new DeployCommand($wrangler, $this->stager()));

        $exit = $tester->execute([
            '--root' => $this->rootWithoutAccountId(),
            '--env' => 'production',
            '--worker-dir' => $this->workerDir(),
            '--bundle' => $this->bundleFile(),
        ]);

        // A login that reaches exactly one account resolves it without being
        // told, so an absent account id cannot be an error before Wrangler runs.
        self::assertSame(0, $exit, $tester->getDisplay());
        $call = $wrangler->lastCall('deploy');
        self::assertNotNull($call);
        self::assertArrayNotHasKey('CLOUDFLARE_ACCOUNT_ID', $call['target']->credentialEnv());
    }

    public function testWranglerUnableToChooseAnAccountMapsToE075(): void
    {
        $wrangler = new FakeWrangler();
        $wrangler->deployResult = FakeWrangler::failed(
            ['deploy'],
            "✘ [ERROR] More than one account available but unable to select one in non-interactive mode.\n"
            . "  Please set the appropriate `account_id` or assign it to the CLOUDFLARE_ACCOUNT_ID "
            . "environment variable.\n",
        );
        $tester = new CommandTester(new DeployCommand($wrangler, $this->stager()));

        $exit = $tester->execute([
            '--root' => $this->rootWithoutAccountId(),
            '--env' => 'production',
            '--worker-dir' => $this->workerDir(),
            '--bundle' => $this->bundleFile(),
        ]);

        $display = $tester->getDisplay();
        self::assertSame(1, $exit);
        self::assertStringContainsString('ATOMS-E075', $display);
        self::assertStringNotContainsString('ATOMS-E074', $display);
        // Wrangler lists the accounts it can see; that list is the fix.
        self::assertStringContainsString('More than one account available', $display);
    }

    /** A copy of the fixture whose atoms.json carries no account_id. */
    private function rootWithoutAccountId(): string
    {
        $root = $this->tempCopy('sample-app');
        $config = json_decode((string) file_get_contents($root . '/atoms.json'), true);
        unset($config['environments']['production']['account_id']);
        file_put_contents($root . '/atoms.json', json_encode($config, JSON_THROW_ON_ERROR));

        return $root;
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
