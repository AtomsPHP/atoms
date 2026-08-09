<?php

declare(strict_types=1);

namespace Atoms\Cli\Tests\Command;

use Atoms\Cli\Command\DeployCommand;
use Atoms\Cli\Platform\HttpResponse;
use Atoms\Cli\Tests\Support\FakePlatformApi;
use Atoms\Cli\Tests\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class DeployCommandTest extends TestCase
{
    private function bundleFile(): string
    {
        $path = $this->freshDir() . '/bundle.tar.gz';
        file_put_contents($path, (string) gzencode('dummy'));

        return $path;
    }

    public function testSuccessfulDeploy(): void
    {
        $fake = new FakePlatformApi();
        $tester = new CommandTester(new DeployCommand($fake));

        $exit = $tester->execute([
            '--root' => $this->fixtureDir('sample-app'),
            '--env' => 'production',
            '--api-key' => 'atoms_v1_test',
            '--bundle' => $this->bundleFile(),
        ]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('v4', $tester->getDisplay());
        self::assertSame('deploy', $fake->calls[0]['method']);
    }

    public function testValidationFailureMapsToE042(): void
    {
        $fake = new FakePlatformApi(
            deployResponse: new HttpResponse(422, ['error' => ['code' => 'validation_failed', 'message' => 'boundary broke at line 9']]),
        );
        $tester = new CommandTester(new DeployCommand($fake));

        $exit = $tester->execute([
            '--root' => $this->fixtureDir('sample-app'),
            '--env' => 'production',
            '--api-key' => 'atoms_v1_test',
            '--bundle' => $this->bundleFile(),
        ]);

        $display = $tester->getDisplay();
        self::assertSame(1, $exit);
        self::assertStringContainsString('ATOMS-E042', $display);
        self::assertStringContainsString('boundary broke at line 9', $display);
    }

    public function testMissingApiKeyMapsToE072(): void
    {
        putenv('ATOMS_API_KEY');
        $fake = new FakePlatformApi();
        $tester = new CommandTester(new DeployCommand($fake));

        $exit = $tester->execute([
            '--root' => $this->fixtureDir('sample-app'),
            '--env' => 'production',
        ]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('ATOMS-E072', $tester->getDisplay());
        self::assertSame([], $fake->calls, 'no request should be made without credentials');
    }
}
