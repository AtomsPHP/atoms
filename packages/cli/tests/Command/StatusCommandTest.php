<?php

declare(strict_types=1);

namespace Atoms\Cli\Tests\Command;

use Atoms\Cli\Command\StatusCommand;
use Atoms\Cli\Tests\Support\FakeWrangler;
use Atoms\Cli\Tests\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `atoms status` reads fields out of `wrangler versions list --json`, whose
 * shape is Wrangler's, not ours. The original guess put `created_on` at the top
 * level and expected a `workers/message` annotation; a real account has it
 * under `metadata` and no such annotation, so the timestamp silently vanished.
 *
 * Both shapes are pinned here because the parsing is deliberately tolerant, and
 * tolerant parsing is exactly what regresses unnoticed.
 */
final class StatusCommandTest extends TestCase
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
     * @param list<array<string, mixed>> $versions
     */
    private function run(array $versions): string
    {
        $wrangler = new FakeWrangler();
        $wrangler->versionsResult = FakeWrangler::ok(
            ['versions', 'list'],
            json_encode($versions, JSON_THROW_ON_ERROR),
        );

        $tester = new CommandTester(new StatusCommand($wrangler));
        $exit = $tester->execute([
            '--root' => $this->fixtureDir('sample-app'),
            '--env' => 'production',
            '--worker-dir' => $this->workerDir(),
        ]);

        self::assertSame(0, $exit, $tester->getDisplay());

        return $tester->getDisplay();
    }

    /**
     * The shape observed against wrangler 4.118.0 on a real account.
     */
    public function testReadsTheLiveWranglerShape(): void
    {
        $display = $this->run([[
            'id' => 'f00dcafe-0000-4000-8000-000000000001',
            'number' => 1,
            'metadata' => ['created_on' => '2026-08-09T21:00:00.000Z', 'source' => 'wrangler'],
            'annotations' => ['workers/triggered_by' => 'upload'],
        ]]);

        self::assertStringContainsString('f00dcafe-0000-4000-8000-000000000001', $display);
        self::assertStringContainsString('2026-08-09T21:00:00.000Z', $display, 'the timestamp must not be dropped');
        self::assertStringContainsString('upload', $display);
    }

    /**
     * A flatter shape stays readable, so tolerating it is not accidental.
     */
    public function testStillReadsATopLevelCreatedOn(): void
    {
        $display = $this->run([[
            'id' => 'abc',
            'created_on' => '2026-01-01T00:00:00.000Z',
            'annotations' => ['workers/message' => 'a deploy message'],
        ]]);

        self::assertStringContainsString('abc', $display);
        self::assertStringContainsString('2026-01-01T00:00:00.000Z', $display);
        self::assertStringContainsString('a deploy message', $display);
    }

    public function testAVersionWithNeitherFieldStillLists(): void
    {
        $display = $this->run([['id' => 'bare']]);

        self::assertStringContainsString('bare', $display);
    }

    public function testNoVersionsSaysSoRatherThanPrintingNothing(): void
    {
        $display = $this->run([]);

        self::assertStringContainsString('nothing deployed yet', $display);
    }

    /**
     * Wrangler's JSON shape is its own; if it changes under us, show what it
     * actually said instead of claiming there are no versions.
     */
    public function testUnparseableOutputIsShownRatherThanReportedAsEmpty(): void
    {
        $wrangler = new FakeWrangler();
        $wrangler->versionsResult = FakeWrangler::ok(['versions', 'list'], 'not json at all');

        $tester = new CommandTester(new StatusCommand($wrangler));
        $tester->execute([
            '--root' => $this->fixtureDir('sample-app'),
            '--env' => 'production',
            '--worker-dir' => $this->workerDir(),
        ]);

        $display = $tester->getDisplay();
        self::assertStringContainsString('could not parse', $display);
        self::assertStringContainsString('not json at all', $display);
    }
}
