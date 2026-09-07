<?php

declare(strict_types=1);

namespace Atoms\Cli\Tests\Cloudflare;

use Atoms\Cli\Cloudflare\CloudflareTarget;
use Atoms\Cli\Config\AtomsJson;
use Atoms\Cli\Tests\TestCase;
use Atoms\Errors\AtomsError;
use Atoms\Errors\ErrorCode;

/**
 * One precedence order, for every setting and every command:
 *
 * ```text
 * explicit CLI flag
 *     > the caller's own environment
 *     > .env.atoms.<environment>, beside atoms.json
 *     > the selected entry in atoms.json
 * ```
 *
 * A setting with no flag simply has no flag layer. The nearer source wins
 * silently — nothing here compares sources or refuses a disagreement — but
 * every winning source is recorded, and `atoms deploy` prints it before it
 * does anything.
 */
final class ConfigurationPrecedenceTest extends TestCase
{
    private const VARS = ['CLOUDFLARE_API_TOKEN', 'CLOUDFLARE_ACCOUNT_ID', 'ATOMS_CALLBACK_URL', 'CI_CALLBACK_URL'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearEnv();
    }

    protected function tearDown(): void
    {
        $this->clearEnv();
        parent::tearDown();
    }

    public function testCallbackUrlWalksAllFourLayers(): void
    {
        $config = $this->project(['callback_url' => ['production' => 'https://file.example/cb']]);

        // Bottom layer: nothing but atoms.json.
        $target = CloudflareTarget::resolve($config, 'production');
        self::assertSame('https://file.example/cb', $target->callbackUrl);
        self::assertSame('atoms.json "callback_url.production"', $target->sources['callback_url']);

        // The dotenv file beats atoms.json.
        $this->writeDotenv($config->rootDir, 'production', "ATOMS_CALLBACK_URL=https://dotenv.example/cb\n");
        $target = CloudflareTarget::resolve($config, 'production');
        self::assertSame('https://dotenv.example/cb', $target->callbackUrl);
        self::assertSame('.env.atoms.production: ATOMS_CALLBACK_URL', $target->sources['callback_url']);

        // The caller's own environment beats the dotenv file — so a value
        // exported by CI is never overridden by a file in the repository.
        putenv('ATOMS_CALLBACK_URL=https://caller.example/cb');
        $target = CloudflareTarget::resolve($config, 'production');
        self::assertSame('https://caller.example/cb', $target->callbackUrl);
        self::assertSame('caller environment: ATOMS_CALLBACK_URL', $target->sources['callback_url']);

        // And the flag beats everything.
        $target = CloudflareTarget::resolve($config, 'production', callbackUrl: 'https://flag.example/cb');
        self::assertSame('https://flag.example/cb', $target->callbackUrl);
        self::assertSame('--callback-url', $target->sources['callback_url']);
    }

    public function testAccountIdAndApiTokenUseTheSameOrderMinusTheFlagLayer(): void
    {
        $config = $this->project(['environments' => [
            'production' => ['worker_name' => 'acme', 'account_id' => 'from-file', 'debug_endpoints' => false],
        ]]);

        $target = CloudflareTarget::resolve($config, 'production');
        self::assertSame('from-file', $target->accountId);
        self::assertSame('atoms.json', $target->sources['account_id']);
        self::assertNull($target->apiToken);
        self::assertArrayNotHasKey('api_token', $target->sources);

        $this->writeDotenv($config->rootDir, 'production', "CLOUDFLARE_ACCOUNT_ID=from-dotenv\nCLOUDFLARE_API_TOKEN=tok-dotenv\n");
        $target = CloudflareTarget::resolve($config, 'production');
        self::assertSame('from-dotenv', $target->accountId);
        self::assertSame('.env.atoms.production: CLOUDFLARE_ACCOUNT_ID', $target->sources['account_id']);
        self::assertSame('tok-dotenv', $target->apiToken);
        self::assertSame('.env.atoms.production: CLOUDFLARE_API_TOKEN', $target->sources['api_token']);

        putenv('CLOUDFLARE_ACCOUNT_ID=from-caller');
        putenv('CLOUDFLARE_API_TOKEN=tok-caller');
        $target = CloudflareTarget::resolve($config, 'production');
        self::assertSame('from-caller', $target->accountId);
        self::assertSame('caller environment: CLOUDFLARE_ACCOUNT_ID', $target->sources['account_id']);
        self::assertSame('tok-caller', $target->apiToken);
        self::assertSame('caller environment: CLOUDFLARE_API_TOKEN', $target->sources['api_token']);
    }

    /**
     * The file for another target is never consulted, whatever is in it. This
     * is the property the whole contract exists for: a value that belongs to
     * local work cannot decide a production deployment.
     */
    public function testAnotherTargetsFileIsNeverRead(): void
    {
        $config = $this->project(['callback_url' => ['production' => 'https://file.example/cb']]);
        $this->writeDotenv($config->rootDir, 'staging', "ATOMS_CALLBACK_URL=https://staging-only.example/cb\n");
        file_put_contents($config->rootDir . '/.env', "ATOMS_CALLBACK_URL=http://localhost:8000/cb\n");
        file_put_contents($config->rootDir . '/.env.atoms', "ATOMS_CALLBACK_URL=http://generic.example/cb\n");

        $target = CloudflareTarget::resolve($config, 'production');

        self::assertSame('https://file.example/cb', $target->callbackUrl);
    }

    /**
     * A ${VAR} reference in atoms.json resolves against the same two
     * environment layers as everything else, and against nothing else: naming
     * a variable in the committed file must not open a path to values Atoms
     * would otherwise never read.
     */
    public function testAFileReferenceResolvesThroughTheSameLayersAndSaysSo(): void
    {
        $config = $this->project(['callback_url' => ['production' => '${CI_CALLBACK_URL}']]);

        $this->writeDotenv($config->rootDir, 'production', "CI_CALLBACK_URL=https://dotenv.example/cb\n");
        $target = CloudflareTarget::resolve($config, 'production');
        self::assertSame('https://dotenv.example/cb', $target->callbackUrl);
        self::assertSame(
            'atoms.json "callback_url.production" -> .env.atoms.production: CI_CALLBACK_URL',
            $target->sources['callback_url'],
        );

        putenv('CI_CALLBACK_URL=https://caller.example/cb');
        $target = CloudflareTarget::resolve($config, 'production');
        self::assertSame('https://caller.example/cb', $target->callbackUrl);
        self::assertSame(
            'atoms.json "callback_url.production" -> caller environment: CI_CALLBACK_URL',
            $target->sources['callback_url'],
        );
    }

    public function testAnUnreadableTargetFileIsE109AndNotSilentlySkipped(): void
    {
        $config = $this->project([]);
        $this->writeDotenv($config->rootDir, 'production', "not an assignment\n");

        try {
            CloudflareTarget::resolve($config, 'production');
            self::fail('expected ATOMS-E109');
        } catch (AtomsError $e) {
            self::assertSame(ErrorCode::AtomsEnvFileInvalid, $e->errorCode);
        }
    }

    /**
     * The API token is the one value the report names without showing: the
     * same rule that keeps it out of argv and out of every log.
     */
    public function testTheReportShowsEverySourceAndHidesTheCredential(): void
    {
        $config = $this->project(['callback_url' => ['production' => 'https://file.example/cb']]);
        putenv('CLOUDFLARE_API_TOKEN=super-secret-token');

        $rows = CloudflareTarget::resolve($config, 'production')->report();
        $flat = json_encode($rows, JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('super-secret-token', $flat);
        self::assertContains(['API token', '(hidden)', 'caller environment: CLOUDFLARE_API_TOKEN'], $rows);
        self::assertContains(['Callback', 'https://file.example/cb', 'atoms.json "callback_url.production"'], $rows);
        self::assertContains(['Worker', 'acme', 'atoms.json'], $rows);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function project(array $overrides): AtomsJson
    {
        $root = $this->freshDir();
        $json = [
            'project' => 'acme',
            'paths' => ['atoms' => 'app/Atoms'],
            'environments' => [
                'production' => ['worker_name' => 'acme', 'account_id' => '', 'debug_endpoints' => false],
                'staging' => ['worker_name' => 'acme-staging', 'account_id' => '', 'debug_endpoints' => false],
            ],
            ...$overrides,
        ];
        file_put_contents($root . '/atoms.json', json_encode($json, JSON_THROW_ON_ERROR));

        return AtomsJson::load($root . '/atoms.json');
    }

    private function writeDotenv(string $root, string $environment, string $contents): void
    {
        file_put_contents($root . '/.env.atoms.' . $environment, $contents);
    }

    private function clearEnv(): void
    {
        foreach (self::VARS as $name) {
            putenv($name);
        }
    }
}
