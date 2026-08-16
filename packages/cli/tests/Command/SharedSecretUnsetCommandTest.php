<?php

declare(strict_types=1);

namespace Atoms\Cli\Tests\Command;

use Atoms\Cli\Command\SharedSecretUnsetCommand;
use Atoms\Cli\Tests\Support\FakeWrangler;
use Atoms\Cli\Tests\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `atoms shared-secret:unset` closes a rotation window, so that a rotation
 * started from a pipeline can be finished from one too.
 */
final class SharedSecretUnsetCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        putenv('CLOUDFLARE_API_TOKEN=cf-token');
        putenv('CLOUDFLARE_ACCOUNT_ID');
    }

    protected function tearDown(): void
    {
        putenv('CLOUDFLARE_API_TOKEN');
        parent::tearDown();
    }

    private function workerDir(): string
    {
        $dir = $this->freshDir();
        file_put_contents($dir . '/wrangler.jsonc', json_encode(['name' => 'w'], JSON_THROW_ON_ERROR));

        return $dir;
    }

    private function invoke(FakeWrangler $wrangler): CommandTester
    {
        $tester = new CommandTester(new SharedSecretUnsetCommand($wrangler));
        $tester->execute([
            '--root' => $this->fixtureDir('sample-app'),
            '--env' => 'production',
            '--worker-dir' => $this->workerDir(),
        ]);

        return $tester;
    }

    /** @param list<string> $names */
    private static function secretList(array $names): FakeWrangler
    {
        $wrangler = new FakeWrangler();
        $wrangler->listSecretsResult = FakeWrangler::ok([], json_encode(
            array_map(static fn (string $n): array => ['name' => $n], $names),
            JSON_THROW_ON_ERROR,
        ));

        return $wrangler;
    }

    public function testDeletesTheOverlapKey(): void
    {
        $wrangler = self::secretList(['ATOMS_SHARED_SECRET', 'ATOMS_SHARED_SECRET_PREVIOUS']);

        $tester = $this->invoke($wrangler);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $call = $wrangler->lastCall('deleteSecret');
        self::assertNotNull($call);
        self::assertSame('ATOMS_SHARED_SECRET_PREVIOUS', $call['args']['key']);
    }

    /**
     * The current secret has no unset path at all: removing it makes every
     * route but `GET /healthz` answer misconfigured.
     */
    public function testNeverTouchesTheCurrentSecret(): void
    {
        $wrangler = self::secretList(['ATOMS_SHARED_SECRET', 'ATOMS_SHARED_SECRET_PREVIOUS']);

        $this->invoke($wrangler);

        foreach ($wrangler->calls as $call) {
            if ($call['method'] === 'deleteSecret') {
                self::assertNotSame('ATOMS_SHARED_SECRET', $call['args']['key']);
            }
        }
    }

    /** Running it again after the window is closed must not fail a pipeline. */
    public function testSucceedsWhenTheKeyIsAlreadyGone(): void
    {
        $wrangler = self::secretList(['ATOMS_SHARED_SECRET']);

        $tester = $this->invoke($wrangler);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertNull($wrangler->lastCall('deleteSecret'));
        self::assertStringContainsString('nothing to do', $tester->getDisplay());
    }

    /**
     * An unreadable list must not be read as "already closed" — that would
     * report a closed window while the old secret stays live and trusted.
     */
    public function testAttemptsTheDeleteWhenTheListIsUnreadable(): void
    {
        $wrangler = new FakeWrangler();
        $wrangler->listSecretsResult = FakeWrangler::failed([], 'boom');

        $tester = $this->invoke($wrangler);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertNotNull($wrangler->lastCall('deleteSecret'));
    }

    public function testReportsAFailedDelete(): void
    {
        $wrangler = self::secretList(['ATOMS_SHARED_SECRET_PREVIOUS']);
        $wrangler->deleteSecretResult = FakeWrangler::failed([], 'wrangler exploded');

        $tester = $this->invoke($wrangler);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('wrangler exploded', $tester->getDisplay());
    }
}
