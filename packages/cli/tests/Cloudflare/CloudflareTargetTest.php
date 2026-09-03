<?php

declare(strict_types=1);

namespace Atoms\Cli\Tests\Cloudflare;

use Atoms\Cli\Cloudflare\CloudflareTarget;
use Atoms\Cli\Cloudflare\WranglerBinary;
use Atoms\Cli\Config\AtomsJson;
use Atoms\Cli\Tests\Support\FakeProcessRunner;
use Atoms\Cli\Tests\TestCase;
use Atoms\Errors\AtomsError;

final class CloudflareTargetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        putenv('CLOUDFLARE_API_TOKEN');
        putenv('CLOUDFLARE_ACCOUNT_ID');
        putenv(CloudflareTarget::CALLBACK_VAR);
        putenv(WranglerBinary::ENV_OVERRIDE);
    }

    protected function tearDown(): void
    {
        putenv('CLOUDFLARE_API_TOKEN');
        putenv('CLOUDFLARE_ACCOUNT_ID');
        putenv(CloudflareTarget::CALLBACK_VAR);
        putenv(WranglerBinary::ENV_OVERRIDE);
        parent::tearDown();
    }

    /**
     * The callback URL has the same shape as `account_id`: an explicit value
     * beats the environment, which beats atoms.json. A callback URL is often
     * per machine (a tunnel host, a local port), so the committed file must
     * not be its only home.
     */
    public function testCallbackUrlResolvesFlagThenEnvironmentThenAtomsJson(): void
    {
        $fromJson = CloudflareTarget::resolve($this->sampleApp(), 'staging');
        self::assertSame('https://staging.acme.example.com', $fromJson->callbackUrl);
        self::assertSame(
            [CloudflareTarget::CALLBACK_VAR => 'https://staging.acme.example.com'],
            $fromJson->runtimeVars(),
        );

        putenv(CloudflareTarget::CALLBACK_VAR . '=https://tunnel.example.test/atoms/callback');
        $fromEnv = CloudflareTarget::resolve($this->sampleApp(), 'staging');
        self::assertSame('https://tunnel.example.test/atoms/callback', $fromEnv->callbackUrl);

        $fromFlag = CloudflareTarget::resolve(
            $this->sampleApp(),
            'staging',
            callbackUrl: 'http://127.0.0.1:8000/atoms/callback',
        );
        self::assertSame('http://127.0.0.1:8000/atoms/callback', $fromFlag->callbackUrl);
    }

    public function testCallbackUrlIsPerEnvironmentAndAbsentWhenNothingConfiguresIt(): void
    {
        self::assertSame(
            'https://acme.example.com',
            CloudflareTarget::resolve($this->sampleApp(), 'production')->callbackUrl,
        );

        $root = $this->tempCopy('sample-app');
        $json = json_decode((string) file_get_contents($root . '/atoms.json'), true);
        unset($json['callback_url']);
        file_put_contents($root . '/atoms.json', json_encode($json, JSON_THROW_ON_ERROR));

        $target = CloudflareTarget::resolve(AtomsJson::load($root . '/atoms.json'), 'production');
        self::assertNull($target->callbackUrl);
        // An empty environment value is "unset", not a URL.
        putenv(CloudflareTarget::CALLBACK_VAR . '=');
        self::assertNull(CloudflareTarget::resolve(AtomsJson::load($root . '/atoms.json'), 'production')->callbackUrl);
        self::assertSame([], $target->runtimeVars());
    }

    public function testExplicitTokenBeatsTheEnvironment(): void
    {
        putenv('CLOUDFLARE_API_TOKEN=from-env');
        $target = CloudflareTarget::resolve($this->sampleApp(), 'production', 'from-flag');

        self::assertSame('from-flag', $target->apiToken);
    }

    public function testTheEnvironmentSuppliesWhatAtomsJsonDoesNot(): void
    {
        putenv('CLOUDFLARE_API_TOKEN=from-env');
        $target = CloudflareTarget::resolve($this->sampleApp(), 'production');

        self::assertSame('from-env', $target->apiToken);
        // atoms.json carries the account id for this fixture, so it wins the
        // fallback chain without the environment being consulted.
        self::assertSame('cf-account-1234', $target->accountId);
    }

    public function testNoTokenResolvesAndInjectsNothing(): void
    {
        // The `wrangler login` posture: no token anywhere, credentials still
        // resolve. Wrangler is left to consult the OAuth session it owns, and
        // an absent CLOUDFLARE_API_TOKEN in the child environment is what hands
        // that decision to it.
        $target = CloudflareTarget::resolve($this->sampleApp(), 'production');

        self::assertNull($target->apiToken);
        self::assertSame(['CLOUDFLARE_ACCOUNT_ID' => 'cf-account-1234'], $target->credentialEnv());
    }

    public function testNoAccountIdResolvesAndInjectsNothing(): void
    {
        // Also not pre-empted: a login that reaches exactly one account needs
        // no telling, and only Wrangler knows how many it reaches. Ambiguity
        // is Wrangler's to report, and arrives as ATOMS-E075 from its output.
        $root = $this->tempCopy('sample-app');
        $json = json_decode((string) file_get_contents($root . '/atoms.json'), true);
        unset($json['environments']['production']['account_id']);
        file_put_contents($root . '/atoms.json', json_encode($json, JSON_THROW_ON_ERROR));

        $target = CloudflareTarget::resolve(
            \Atoms\Cli\Config\AtomsJson::load($root . '/atoms.json'),
            'production',
        );

        self::assertSame('', $target->accountId);
        self::assertSame([], $target->credentialEnv());
    }

    public function testWorkerNameFallsBackToTheProject(): void
    {
        $config = $this->sampleApp();
        $target = CloudflareTarget::resolve($config, 'production', 'token');
        self::assertSame('acme-games', $target->workerName);

        // With no worker_name in atoms.json, the project names the Worker.
        $root = $this->tempCopy('sample-app');
        $json = json_decode((string) file_get_contents($root . '/atoms.json'), true);
        unset($json['environments']['production']['worker_name']);
        file_put_contents($root . '/atoms.json', json_encode($json, JSON_THROW_ON_ERROR));

        $target = CloudflareTarget::resolve(
            \Atoms\Cli\Config\AtomsJson::load($root . '/atoms.json'),
            'production',
            'token',
        );
        self::assertSame('acme-games', $target->workerName);
    }

    public function testWorkerDirDefaultsUnderTheRepoRootAndResolvesRelativeOverrides(): void
    {
        $config = $this->sampleApp();

        $target = CloudflareTarget::resolve($config, 'production', 'token');
        self::assertSame($config->rootDir . '/' . CloudflareTarget::DEFAULT_WORKER_DIR, $target->workerDir);

        $target = CloudflareTarget::resolve($config, 'production', 'token', 'vendor/worker');
        self::assertSame($config->rootDir . '/vendor/worker', $target->workerDir);

        $target = CloudflareTarget::resolve($config, 'production', 'token', '/opt/atoms-worker');
        self::assertSame('/opt/atoms-worker', $target->workerDir);
    }

    public function testDevNeedsNoCredentials(): void
    {
        $target = CloudflareTarget::resolve($this->sampleApp(), 'staging');

        self::assertNull($target->apiToken);
        self::assertSame([], array_diff_key($target->credentialEnv(), ['CLOUDFLARE_ACCOUNT_ID' => '']));
    }

    public function testCredentialEnvOmitsWhatIsNotSet(): void
    {
        $target = CloudflareTarget::resolve($this->sampleApp(), 'staging');

        self::assertArrayNotHasKey('CLOUDFLARE_API_TOKEN', $target->credentialEnv());
    }

    public function testDebugEndpointsDefaultOffAndYieldNoRuntimeVars(): void
    {
        $target = CloudflareTarget::resolve($this->sampleApp(), 'production', 'token');

        self::assertFalse($target->debugEndpoints);
        // The fixture's callback_url still rides along; the point is that no
        // debug var does.
        self::assertArrayNotHasKey(CloudflareTarget::DEBUG_ENDPOINTS_VAR, $target->runtimeVars());
    }

    public function testDebugEndpointsFromAtomsJsonBecomeTheWranglerVar(): void
    {
        $root = $this->tempCopy('sample-app');
        $json = json_decode((string) file_get_contents($root . '/atoms.json'), true);
        $json['environments']['production']['debug_endpoints'] = true;
        file_put_contents($root . '/atoms.json', json_encode($json, JSON_THROW_ON_ERROR));

        $target = CloudflareTarget::resolve(
            \Atoms\Cli\Config\AtomsJson::load($root . '/atoms.json'),
            'production',
            'token',
        );

        self::assertTrue($target->debugEndpoints);
        self::assertSame(
            ['ATOMS_DEBUG_ENDPOINTS' => '1', CloudflareTarget::CALLBACK_VAR => 'https://acme.example.com'],
            $target->runtimeVars(),
        );
    }

    public function testANonBooleanDebugEndpointsIsRefusedNotCoerced(): void
    {
        $root = $this->tempCopy('sample-app');
        $json = json_decode((string) file_get_contents($root . '/atoms.json'), true);
        // "false" the string would silently enable a debug surface if coerced.
        $json['environments']['production']['debug_endpoints'] = 'false';
        file_put_contents($root . '/atoms.json', json_encode($json, JSON_THROW_ON_ERROR));

        $this->expectException(AtomsError::class);
        $this->expectExceptionMessageMatches('/ATOMS-E070.*debug_endpoints.*boolean/s');
        \Atoms\Cli\Config\AtomsJson::load($root . '/atoms.json');
    }

    public function testInvokeUrlIsThePrefixlessSingleTenantRoute(): void
    {
        $target = CloudflareTarget::resolve($this->sampleApp(), 'production', 'token');

        self::assertSame(
            'https://acme-games.example.workers.dev/invoke/GameRoom/g-1/ping',
            $target->invokeUrl('GameRoom', 'g-1', 'ping'),
        );
    }

    public function testWorkerDirWithoutAWranglerConfigIsE076(): void
    {
        $target = CloudflareTarget::resolve($this->sampleApp(), 'production', 'token', $this->freshDir());

        $this->expectException(AtomsError::class);
        $this->expectExceptionMessageMatches('/ATOMS-E076/');
        $target->assertWorkerDir();
    }

    public function testWranglerResolutionPrefersTheLocalPinOverPath(): void
    {
        $dir = $this->freshDir();
        mkdir($dir . '/node_modules/.bin', 0777, true);
        $local = $dir . '/node_modules/.bin/wrangler';
        file_put_contents($local, "#!/bin/sh\n");
        chmod($local, 0755);

        $target = CloudflareTarget::resolve($this->sampleApp(), 'production', 'token', $dir);
        $runner = new FakeProcessRunner(onPath: ['wrangler' => '/usr/local/bin/wrangler']);

        self::assertSame($local, WranglerBinary::resolve($runner, $target));
    }

    public function testWranglerResolutionFallsBackToPath(): void
    {
        $target = CloudflareTarget::resolve($this->sampleApp(), 'production', 'token', $this->freshDir());
        $runner = new FakeProcessRunner(onPath: ['wrangler' => '/usr/local/bin/wrangler']);

        self::assertSame('/usr/local/bin/wrangler', WranglerBinary::resolve($runner, $target));
    }

    public function testNoWranglerAnywhereIsE073AndNeverFetchesOne(): void
    {
        $target = CloudflareTarget::resolve($this->sampleApp(), 'production', 'token', $this->freshDir());
        $runner = new FakeProcessRunner(onPath: []);

        try {
            WranglerBinary::resolve($runner, $target);
            self::fail('expected ATOMS-E073');
        } catch (AtomsError $e) {
            self::assertStringContainsString('ATOMS-E073', $e->getMessage());
        }

        self::assertSame([], $runner->runs, 'resolution must never run a command — npx is not a fallback');
    }

    public function testAnUnusableWranglerOverrideIsE073RatherThanSilentlyIgnored(): void
    {
        putenv(WranglerBinary::ENV_OVERRIDE . '=/nonexistent/wrangler');
        $target = CloudflareTarget::resolve($this->sampleApp(), 'production', 'token', $this->freshDir());
        $runner = new FakeProcessRunner(onPath: ['wrangler' => '/usr/local/bin/wrangler']);

        $this->expectException(AtomsError::class);
        $this->expectExceptionMessageMatches('/ATOMS-E073/');
        WranglerBinary::resolve($runner, $target);
    }
}
