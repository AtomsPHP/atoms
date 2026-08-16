<?php

declare(strict_types=1);

namespace Atoms\Laravel\Tests;

/**
 * Exercises config/atoms.php's ATOMS_CALLBACK_PATH env() call directly: a
 * real environment variable, set before the app boots, must relocate the
 * auto-registered callback route — as opposed to CallbackRouteTest's
 * coverage of the default path. Mirrors ConfigEnvOverrideTest's pattern of
 * putenv() in setUp()/tearDown() around parent::setUp().
 */
final class CallbackPathEnvOverrideTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('ATOMS_CALLBACK_PATH=/hooks/atoms');

        parent::setUp();
    }

    protected function tearDown(): void
    {
        putenv('ATOMS_CALLBACK_PATH');

        parent::tearDown();
    }

    public function testEnvVariableRelocatesTheNamedRoute(): void
    {
        self::assertSame('/hooks/atoms', config('atoms.callback.path'));
        self::assertTrue($this->app['router']->has('atoms.callback'));
        self::assertStringEndsWith('/hooks/atoms', route('atoms.callback'));
    }

    public function testRelocatedPathReachesTheKernel(): void
    {
        [$server, $body] = $this->signedCallback('methods', [
            'atom' => ['type' => \Atoms\Laravel\Tests\Fixtures\GameRoom::class, 'id' => 'g-1'],
            'method' => 'add',
            'args' => [2, 3],
        ]);

        $response = $this->call('POST', '/hooks/atoms', [], [], [], $server, $body);

        $response->assertStatus(200);
        $response->assertJson(['result' => 5]);
    }

    public function testDefaultPathIsNoLongerRegisteredWhenRelocated(): void
    {
        [$server, $body] = $this->signedCallback('methods', [
            'atom' => ['type' => \Atoms\Laravel\Tests\Fixtures\GameRoom::class, 'id' => 'g-1'],
            'method' => 'add',
            'args' => [2, 3],
        ]);

        $response = $this->call('POST', '/atoms/callback', [], [], [], $server, $body);

        $response->assertStatus(404);
    }
}
