<?php

declare(strict_types=1);

namespace Atoms\Cli\Tests\Command;

use Atoms\Cli\Command\DevCommand;
use Atoms\Cli\Tests\Support\FakeWrangler;
use Atoms\Cli\Tests\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `atoms dev` and `atoms deploy` must agree on what atoms.json's
 * `debug_endpoints` means: one declaration, forwarded to Wrangler as a `--var`
 * on both paths. The deploy half lives in {@see DeployCommandTest}.
 */
final class DevCommandTest extends TestCase
{
    public function testDebugEndpointsDefaultOffInDevToo(): void
    {
        $wrangler = new FakeWrangler();
        $tester = new CommandTester(new DevCommand($wrangler));

        $exit = $tester->execute([
            '--root' => $this->fixtureDir('sample-app'),
            '--env' => 'staging',
            '--no-build' => true,
        ]);

        self::assertSame(0, $exit, $tester->getDisplay());
        $dev = $wrangler->lastCall('dev');
        self::assertNotNull($dev);
        self::assertArrayNotHasKey('ATOMS_DEBUG_ENDPOINTS', $dev['args']['vars']);
    }

    public function testDebugEndpointsInAtomsJsonReachWranglerDevAlongsideTheCallbackVar(): void
    {
        $root = $this->tempCopy('sample-app');
        $config = json_decode((string) file_get_contents($root . '/atoms.json'), true);
        $config['environments']['staging']['debug_endpoints'] = true;
        file_put_contents($root . '/atoms.json', json_encode($config, JSON_THROW_ON_ERROR));

        $wrangler = new FakeWrangler();
        $tester = new CommandTester(new DevCommand($wrangler));

        $exit = $tester->execute([
            '--root' => $root,
            '--env' => 'staging',
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
        // Local dev runs with bearer auth off, where the flag is the only gate
        // in front of /debug — enabling it must say so.
        self::assertStringContainsString('ATOMS_DEBUG_ENDPOINTS=1', $tester->getDisplay());
        self::assertStringContainsString('only', $tester->getDisplay());
    }
}
