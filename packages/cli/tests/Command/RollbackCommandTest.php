<?php

declare(strict_types=1);

namespace Atoms\Cli\Tests\Command;

use Atoms\Cli\Command\RollbackCommand;
use Atoms\Cli\Tests\Support\FakeWrangler;
use Atoms\Cli\Tests\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The caveat this command prints is the whole remedy for a real surprise:
 * `wrangler secret put` mints a Worker version, so on a Worker whose last two
 * versions are a deploy and a secret rotation, a bare rollback lands on the
 * same code. Observed on a real account. Losing the wording would quietly undo
 * it, so it is pinned rather than trusted.
 */
final class RollbackCommandTest extends TestCase
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
        file_put_contents($dir . '/wrangler.jsonc', '{}');

        return $dir;
    }

    /**
     * @param array<string, string> $extra
     */
    private function rollback(FakeWrangler $wrangler, array $extra = []): CommandTester
    {
        $tester = new CommandTester(new RollbackCommand($wrangler));
        $tester->execute([
            '--root' => $this->fixtureDir('sample-app'),
            '--env' => 'production',
            '--worker-dir' => $this->workerDir(),
        ] + $extra);

        return $tester;
    }

    public function testSuccessSaysRollbackMovesTheVersionNotTheBundle(): void
    {
        $tester = $this->rollback(new FakeWrangler());

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());

        $display = $tester->getDisplay();
        self::assertStringContainsString('not the bundle', $display);
        self::assertStringContainsString('secret', $display, 'must name a secret as a version-minting action');
        self::assertStringContainsString('atoms status', $display, 'must point somewhere actionable');
    }

    public function testABareRollbackAsksWranglerForThePreviousVersion(): void
    {
        $wrangler = new FakeWrangler();
        $this->rollback($wrangler);

        $call = $wrangler->lastCall('rollback');
        self::assertNotNull($call);
        self::assertNull($call['args']['versionId']);
    }

    public function testAVersionIdIsPassedThrough(): void
    {
        $wrangler = new FakeWrangler();
        $this->rollback($wrangler, ['version' => 'f00dcafe-0000-4000-8000-000000000001']);

        $call = $wrangler->lastCall('rollback');
        self::assertNotNull($call);
        self::assertSame('f00dcafe-0000-4000-8000-000000000001', $call['args']['versionId']);
    }

    /**
     * A failed rollback must not print a caveat about a rollback that did not
     * happen — the reassurance would read as confirmation.
     */
    public function testAFailedRollbackSaysNothingAboutVersions(): void
    {
        $wrangler = new FakeWrangler();
        $wrangler->rollbackResult = FakeWrangler::failed(['rollback'], "no deployments found\n");

        $tester = $this->rollback($wrangler);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringNotContainsString('not the bundle', $tester->getDisplay());
    }
}
