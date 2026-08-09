<?php

declare(strict_types=1);

namespace Atoms\Cli\Tests\Command;

use Atoms\Cli\Cloudflare\SecretName;
use Atoms\Cli\Command\SecretsListCommand;
use Atoms\Cli\Command\SecretsSetCommand;
use Atoms\Cli\Tests\Support\FakeWrangler;
use Atoms\Cli\Tests\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class SecretsCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        putenv('CLOUDFLARE_API_TOKEN');
        putenv('CLOUDFLARE_ACCOUNT_ID');
    }

    /**
     * The bug this guards against is silent: a secret stored under its bare
     * name is accepted by Cloudflare and then reads back as null from
     * `$this->config()`, because the Worker's config.get allowlist only
     * resolves the prefixed form (worker/src/bridge.js).
     */
    public function testSetStoresThePrefixedNameTheWorkerCanResolve(): void
    {
        $wrangler = new FakeWrangler();
        $tester = new CommandTester(new SecretsSetCommand($wrangler));

        $exit = $tester->execute([
            '--root' => $this->fixtureDir('sample-app'),
            '--env' => 'production',
            '--api-token' => 'cf-token',
            'key' => 'PAYMENTS_API_KEY',
            'value' => 'sk_live_xyz',
        ]);

        self::assertSame(0, $exit, $tester->getDisplay());

        $call = $wrangler->lastCall('putSecret');
        self::assertNotNull($call);
        self::assertSame('ATOMS_CONFIG_PAYMENTS_API_KEY', $call['args']['key']);
        self::assertSame('sk_live_xyz', $call['args']['value']);

        $display = $tester->getDisplay();
        self::assertStringContainsString('ATOMS_CONFIG_PAYMENTS_API_KEY', $display);
        self::assertStringContainsString("\$this->config('PAYMENTS_API_KEY')", $display);
        self::assertStringNotContainsString('sk_live_xyz', $display, 'the value must never be echoed');
    }

    public function testSetNormalisesKeysTheSameWayTheWorkerDoes(): void
    {
        // bridge.js: configEnvPrefix + key.toUpperCase().replace(/[^A-Z0-9]+/g, '_')
        self::assertSame('ATOMS_CONFIG_PAYMENTS_API_KEY', SecretName::toWorker('payments.api.key'));
        self::assertSame('ATOMS_CONFIG_A_B', SecretName::toWorker('a---b'));
        self::assertSame('PAYMENTS_API_KEY', SecretName::toKey('ATOMS_CONFIG_PAYMENTS_API_KEY'));
        self::assertNull(SecretName::toKey('SOME_OPERATIONAL_SECRET'));
    }

    public function testSetRefusesAnEmptyValue(): void
    {
        $wrangler = new FakeWrangler();
        $tester = new CommandTester(new SecretsSetCommand($wrangler));

        $exit = $tester->execute([
            '--root' => $this->fixtureDir('sample-app'),
            '--env' => 'production',
            '--api-token' => 'cf-token',
            'key' => 'PAYMENTS_API_KEY',
            'value' => '',
        ]);

        self::assertSame(1, $exit);
        self::assertSame([], $wrangler->calls);
    }

    public function testListSeparatesAtomReadableSecretsFromTheRest(): void
    {
        $wrangler = new FakeWrangler();
        $wrangler->listSecretsResult = FakeWrangler::ok(['secret', 'list'], json_encode([
            ['name' => 'ATOMS_CONFIG_PAYMENTS_API_KEY', 'type' => 'secret_text'],
            ['name' => 'ATOMS_APP_KEY', 'type' => 'secret_text'],
        ], JSON_THROW_ON_ERROR));

        $tester = new CommandTester(new SecretsListCommand($wrangler));
        $exit = $tester->execute([
            '--root' => $this->fixtureDir('sample-app'),
            '--env' => 'production',
            '--api-token' => 'cf-token',
        ]);

        $display = $tester->getDisplay();
        self::assertSame(0, $exit, $display);
        self::assertStringContainsString('Readable from Atom code', $display);
        self::assertStringContainsString('- PAYMENTS_API_KEY', $display);
        self::assertStringContainsString('not readable from Atom code', $display);
        self::assertStringContainsString('- ATOMS_APP_KEY', $display);
    }

    public function testListSurvivesWranglerPrefixingItsJsonWithWarnings(): void
    {
        // Wrangler writes proxy/update notices to stdout alongside the JSON.
        $wrangler = new FakeWrangler();
        $wrangler->listSecretsResult = FakeWrangler::ok(
            ['secret', 'list'],
            "▲ [WARNING] Proxy environment variables detected.\n\n"
            . json_encode([['name' => 'ATOMS_CONFIG_TOKEN']], JSON_THROW_ON_ERROR),
        );

        $tester = new CommandTester(new SecretsListCommand($wrangler));
        $tester->execute([
            '--root' => $this->fixtureDir('sample-app'),
            '--env' => 'production',
            '--api-token' => 'cf-token',
        ]);

        self::assertStringContainsString('- TOKEN', $tester->getDisplay());
    }
}
