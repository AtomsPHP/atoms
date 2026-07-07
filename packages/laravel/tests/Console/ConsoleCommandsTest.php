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

    public function testRollbackBuildsArgvWithEnvAndVersion(): void
    {
        $this->artisan('atoms:rollback', ['version' => 'abc123', '--env' => 'production'])
            ->assertExitCode(0);

        self::assertSame([['rollback', '--env', 'production', 'abc123']], $this->runner->calls);
    }

    public function testListWrapsStatus(): void
    {
        $this->artisan('atoms:list', ['--env' => 'staging'])->assertExitCode(0);

        self::assertSame([['status', '--env', 'staging']], $this->runner->calls);
    }

    public function testLocalPassesPlatformParityFlag(): void
    {
        $this->artisan('atoms:local', ['--platform-parity' => true])->assertExitCode(0);

        self::assertSame([['local', '--platform-parity']], $this->runner->calls);
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
