<?php

declare(strict_types=1);

namespace Atoms\Laravel\Tests\Console;

use Atoms\Laravel\Console\BinaryRunner;
use Atoms\Laravel\Tests\Support\FakeBinaryRunner;
use Atoms\Laravel\Tests\TestCase;

final class ConsoleCommandsTest extends TestCase
{
    private FakeBinaryRunner $runner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->runner = new FakeBinaryRunner();
        $this->app->instance(BinaryRunner::class, $this->runner);
    }

    protected function tearDown(): void
    {
        $configPath = $this->app->configPath('atoms.php');
        if (file_exists($configPath)) {
            unlink($configPath);
        }

        parent::tearDown();
    }

    public function testDeployBuildsArgvWithEnvAndBundleOptions(): void
    {
        $this->artisan('atoms:deploy', ['--env' => 'staging', '--bundle' => '/tmp/bundle.tar.gz'])
            ->assertExitCode(0);

        self::assertSame([['deploy', '--env', 'staging', '--bundle', '/tmp/bundle.tar.gz']], $this->runner->calls);
    }

    public function testDeployForwardsManifestAndWorkerDir(): void
    {
        $this->artisan('atoms:deploy', [
            '--env' => 'production',
            '--bundle' => '/tmp/bundle.tar.gz',
            '--manifest' => '/tmp/manifest.json',
            '--worker-dir' => '/srv/worker',
            '--callback-url' => 'https://app.example.test/atoms/callback',
        ])->assertExitCode(0);

        self::assertSame([[
            'deploy',
            '--env', 'production',
            '--bundle', '/tmp/bundle.tar.gz',
            '--manifest', '/tmp/manifest.json',
            '--worker-dir', '/srv/worker',
            '--callback-url', 'https://app.example.test/atoms/callback',
        ]], $this->runner->calls);
    }

    public function testRollbackBuildsArgvWithEnvAndVersion(): void
    {
        $this->artisan('atoms:rollback', ['version' => 'abc123', '--env' => 'production'])
            ->assertExitCode(0);

        self::assertSame([['rollback', '--env', 'production', 'abc123']], $this->runner->calls);
    }

    public function testRollbackForwardsMessageAndWorkerDir(): void
    {
        $this->artisan('atoms:rollback', [
            '--env' => 'production',
            '--message' => 'bad deploy',
            '--worker-dir' => '/srv/worker',
        ])->assertExitCode(0);

        self::assertSame(
            [['rollback', '--env', 'production', '--message', 'bad deploy', '--worker-dir', '/srv/worker']],
            $this->runner->calls,
        );
    }

    public function testListWrapsStatus(): void
    {
        $this->artisan('atoms:list', ['--env' => 'staging'])->assertExitCode(0);

        self::assertSame([['status', '--env', 'staging']], $this->runner->calls);
    }

    /**
     * `atoms:local` (and the Docker runtime image behind `atoms local`) is
     * gone; `atoms dev` serves the Worker through `wrangler dev` instead.
     */
    public function testDevForwardsEnvAndPort(): void
    {
        $this->artisan('atoms:dev', ['--env' => 'staging', '--port' => '8788'])->assertExitCode(0);

        self::assertSame([['dev', '--env', 'staging', '--port', '8788']], $this->runner->calls);
    }

    public function testDevForwardsCallbackUrlWorkerDirAndNoBuild(): void
    {
        $this->artisan('atoms:dev', [
            '--callback-url' => 'http://127.0.0.1:8000/atoms/callback',
            '--worker-dir' => '/srv/worker',
            '--no-build' => true,
        ])->assertExitCode(0);

        self::assertSame([[
            'dev',
            '--callback-url', 'http://127.0.0.1:8000/atoms/callback',
            '--worker-dir', '/srv/worker',
            '--no-build',
        ]], $this->runner->calls);
    }

    public function testMakeAtomBuildsArgvWithFlags(): void
    {
        $this->artisan('make:atom', [
            'name' => 'GameRoom',
            '--with-methods' => true,
            '--with-migration' => true,
            '--websocket' => true,
        ])->assertExitCode(0);

        self::assertSame(
            [['make:atom', 'GameRoom', '--with-methods', '--with-migration', '--websocket']],
            $this->runner->calls,
        );
    }

    public function testInstallRunsInitAndPublishesConfig(): void
    {
        $configPath = $this->app->configPath('atoms.php');
        if (file_exists($configPath)) {
            unlink($configPath);
        }

        $this->artisan('atoms:install')->assertExitCode(0);

        self::assertSame([['init']], $this->runner->calls);
        self::assertFileExists($configPath);
    }
}
