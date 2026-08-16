<?php

declare(strict_types=1);

namespace Atoms\Cli\Tests\Command;

use Atoms\Cli\Command\SecretsRootCommand;
use Atoms\Cli\Tests\Support\FakeWrangler;
use Atoms\Cli\Tests\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `atoms secrets:root` is the only CLI path to ATOMS_SHARED_SECRET, and the
 * one that lets a pipeline configure the Worker it just deployed.
 */
final class SecretsRootCommandTest extends TestCase
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

    /** @param list<string> $extraArgs */
    private function invoke(FakeWrangler $wrangler, string $stdin, array $extraArgs = []): CommandTester
    {
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        fwrite($stream, $stdin);
        rewind($stream);

        $tester = new CommandTester(new SecretsRootCommand($wrangler, $stream));
        $tester->execute([
            '--root' => $this->fixtureDir('sample-app'),
            '--env' => 'production',
            '--worker-dir' => $this->workerDir(),
            ...array_fill_keys($extraArgs, true),
        ]);
        fclose($stream);

        return $tester;
    }

    private function secret(string $byte = "\x41"): string
    {
        return base64_encode(str_repeat($byte, 32));
    }

    public function testStoresTheUnprefixedNameTheWorkerActuallyReads(): void
    {
        $wrangler = new FakeWrangler();
        $secret = $this->secret();

        $tester = $this->invoke($wrangler, $secret);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $call = $wrangler->lastCall('putSecret');
        self::assertNotNull($call);
        self::assertSame('ATOMS_SHARED_SECRET', $call['args']['key'], 'the root must never carry the ATOMS_CONFIG_ prefix');
        self::assertSame($secret, $call['args']['value']);
        self::assertStringNotContainsString($secret, $tester->getDisplay(), 'the value must never be echoed');
    }

    public function testPreviousSetsTheRotationOverlapKey(): void
    {
        $wrangler = new FakeWrangler();

        $tester = $this->invoke($wrangler, $this->secret(), ['--previous']);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame('ATOMS_SHARED_SECRET_PREVIOUS', $wrangler->lastCall('putSecret')['args']['key']);
    }

    /**
     * Running on every deploy must not mint a Worker version every time, so an
     * existing secret is left alone unless --force says otherwise.
     */
    public function testSkipsWhenTheWorkerAlreadyHasTheSecret(): void
    {
        $wrangler = new FakeWrangler();
        $wrangler->listSecretsResult = FakeWrangler::ok([], json_encode([['name' => 'ATOMS_SHARED_SECRET']], JSON_THROW_ON_ERROR));

        $tester = $this->invoke($wrangler, $this->secret());

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertNull($wrangler->lastCall('putSecret'), 'an existing root must not be replaced by a routine deploy');
        self::assertStringContainsString('already set', $tester->getDisplay());
    }

    public function testForceOverwritesAnExistingSecret(): void
    {
        $wrangler = new FakeWrangler();
        $wrangler->listSecretsResult = FakeWrangler::ok([], json_encode([['name' => 'ATOMS_SHARED_SECRET']], JSON_THROW_ON_ERROR));

        $tester = $this->invoke($wrangler, $this->secret("\x42"), ['--force']);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame($this->secret("\x42"), $wrangler->lastCall('putSecret')['args']['value']);
    }

    /**
     * A pipeline secret that is a passphrase rather than 32 base64 bytes would
     * otherwise deploy a Worker that answers misconfigured on every route.
     * Failing here names the problem while the log still says which step.
     */
    public function testRejectsAMalformedSecretBeforeSendingIt(): void
    {
        $wrangler = new FakeWrangler();

        $tester = $this->invoke($wrangler, 'hunter2');

        self::assertSame(1, $tester->getStatusCode());
        self::assertNull($wrangler->lastCall('putSecret'));
        self::assertStringContainsString('ATOMS-E105', $tester->getDisplay());
    }

    public function testRejectsEmptyStdin(): void
    {
        $wrangler = new FakeWrangler();

        $tester = $this->invoke($wrangler, '');

        self::assertSame(1, $tester->getStatusCode());
        self::assertNull($wrangler->lastCall('putSecret'));
    }

    /**
     * An unreadable `secret list` must not be read as "already set" — skipping
     * wrongly leaves a Worker that never gets its secret, while a redundant
     * put costs only a version.
     */
    public function testFailsOpenTowardsSettingWhenTheListIsUnreadable(): void
    {
        $wrangler = new FakeWrangler();
        $wrangler->listSecretsResult = FakeWrangler::failed([], 'boom');

        $tester = $this->invoke($wrangler, $this->secret());

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertNotNull($wrangler->lastCall('putSecret'));
    }
}
