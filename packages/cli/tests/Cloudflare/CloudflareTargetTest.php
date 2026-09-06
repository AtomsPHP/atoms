<?php

declare(strict_types=1);

namespace Atoms\Cli\Tests\Cloudflare;

use Atoms\Cli\Cloudflare\CloudflareTarget;
use Atoms\Cli\Cloudflare\RuntimeStamp;
use Atoms\Cli\Cloudflare\WranglerBinary;
use Atoms\Cli\Config\AtomsJson;
use Atoms\Cli\Release\RuntimeVersion;
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
        putenv('DEPLOY_CALLBACK_URL');
        putenv('LOCAL_CALLBACK_URL');
    }

    protected function tearDown(): void
    {
        putenv('CLOUDFLARE_API_TOKEN');
        putenv('CLOUDFLARE_ACCOUNT_ID');
        putenv(CloudflareTarget::CALLBACK_VAR);
        putenv(WranglerBinary::ENV_OVERRIDE);
        putenv('DEPLOY_CALLBACK_URL');
        putenv('LOCAL_CALLBACK_URL');
        parent::tearDown();
    }

    public function testDeploymentCallbackUrlComesFromAtomsJson(): void
    {
        $fromJson = CloudflareTarget::resolve($this->sampleApp(), 'staging');
        self::assertSame('https://staging.acme.example.com', $fromJson->callbackUrl);
        self::assertSame(
            [CloudflareTarget::CALLBACK_VAR => 'https://staging.acme.example.com'],
            $fromJson->runtimeVars(),
        );

        putenv('DEPLOY_CALLBACK_URL=https://ci.example.test/atoms/callback');
        try {
            $root = $this->tempCopy('sample-app');
            $json = json_decode((string) file_get_contents($root . '/atoms.json'), true, 512, JSON_THROW_ON_ERROR);
            $json['callback_url']['staging'] = '${DEPLOY_CALLBACK_URL}';
            file_put_contents($root . '/atoms.json', json_encode($json, JSON_THROW_ON_ERROR));

            $fromIndirection = CloudflareTarget::resolve(
                AtomsJson::load($root . '/atoms.json'),
                'staging',
            );
            self::assertSame('https://ci.example.test/atoms/callback', $fromIndirection->callbackUrl);
        } finally {
            putenv('DEPLOY_CALLBACK_URL');
        }
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

    public function testAccountIdFallsBackToTheEnvironmentWhenTheFileOmitsIt(): void
    {
        $root = $this->tempCopy('sample-app');
        $json = json_decode((string) file_get_contents($root . '/atoms.json'), true, 512, JSON_THROW_ON_ERROR);
        unset($json['environments']['production']['account_id']);
        file_put_contents($root . '/atoms.json', json_encode($json, JSON_THROW_ON_ERROR));
        putenv('CLOUDFLARE_ACCOUNT_ID=env-account-5678');

        $target = CloudflareTarget::resolve(AtomsJson::load($root . '/atoms.json'), 'production');

        self::assertSame('env-account-5678', $target->accountId);
        self::assertSame(['CLOUDFLARE_ACCOUNT_ID' => 'env-account-5678'], $target->credentialEnv());
    }

    public function testAccountIdFromTheFileAndEnvironmentMustAgree(): void
    {
        putenv('CLOUDFLARE_ACCOUNT_ID=cf-account-1234');
        $target = CloudflareTarget::resolve($this->sampleApp(), 'production', 'token');
        self::assertSame('cf-account-1234', $target->accountId);

        putenv('CLOUDFLARE_ACCOUNT_ID=other-account');
        $this->expectException(AtomsError::class);
        $this->expectExceptionMessageMatches('/ATOMS-E070.*account_id.*CLOUDFLARE_ACCOUNT_ID/s');
        CloudflareTarget::resolve($this->sampleApp(), 'production', 'token');
    }

    public function testWorkerNameIsRequiredAndDoesNotFallBackToTheProject(): void
    {
        $root = $this->tempCopy('sample-app');
        $json = json_decode((string) file_get_contents($root . '/atoms.json'), true);
        unset($json['environments']['production']['worker_name']);
        file_put_contents($root . '/atoms.json', json_encode($json, JSON_THROW_ON_ERROR));

        $this->expectException(AtomsError::class);
        $this->expectExceptionMessageMatches('/ATOMS-E070.*worker_name/s');
        AtomsJson::load($root . '/atoms.json');
    }

    public function testEmptyWorkerNameIsRejected(): void
    {
        $root = $this->tempCopy('sample-app');
        $json = json_decode((string) file_get_contents($root . '/atoms.json'), true, 512, JSON_THROW_ON_ERROR);
        $json['environments']['production']['worker_name'] = '   ';
        file_put_contents($root . '/atoms.json', json_encode($json, JSON_THROW_ON_ERROR));

        $this->expectException(AtomsError::class);
        $this->expectExceptionMessageMatches('/ATOMS-E070.*worker_name/s');
        AtomsJson::load($root . '/atoms.json');
    }

    public function testWorkerDirDefaultsUnderTheRepoRootAndResolvesRelativeOverrides(): void
    {
        $config = $this->sampleApp();

        $target = CloudflareTarget::resolve($config, 'production', 'token');
        self::assertSame('atoms-worker', CloudflareTarget::DEFAULT_WORKER_DIR);
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

    public function testLegacyEndpointIsIgnored(): void
    {
        $root = $this->tempCopy('sample-app');
        $json = json_decode((string) file_get_contents($root . '/atoms.json'), true, 512, JSON_THROW_ON_ERROR);
        $json['environments']['production']['endpoint'] = 'https://legacy.example.test';
        file_put_contents($root . '/atoms.json', json_encode($json, JSON_THROW_ON_ERROR));

        $config = AtomsJson::load($root . '/atoms.json');
        self::assertArrayNotHasKey('endpoint', $config->environment('production'));
        self::assertArrayNotHasKey('endpoint', get_object_vars(CloudflareTarget::resolve($config, 'production', 'token')));
    }

    public function testMalformedCallbackReferenceIsE070(): void
    {
        $root = $this->tempCopy('sample-app');
        $json = json_decode((string) file_get_contents($root . '/atoms.json'), true, 512, JSON_THROW_ON_ERROR);
        $json['callback_url']['production'] = '${NOT-A_VALID_NAME}';
        file_put_contents($root . '/atoms.json', json_encode($json, JSON_THROW_ON_ERROR));

        $this->expectException(AtomsError::class);
        $this->expectExceptionMessageMatches('/ATOMS-E070.*callback_url/s');
        CloudflareTarget::resolve(AtomsJson::load($root . '/atoms.json'), 'production');
    }

    public function testCallbackReferenceMustResolveToANonEmptyEnvironmentValue(): void
    {
        $root = $this->tempCopy('sample-app');
        $json = json_decode((string) file_get_contents($root . '/atoms.json'), true, 512, JSON_THROW_ON_ERROR);
        $json['callback_url']['production'] = '${DEPLOY_CALLBACK_URL}';
        file_put_contents($root . '/atoms.json', json_encode($json, JSON_THROW_ON_ERROR));
        putenv('DEPLOY_CALLBACK_URL=');

        $this->expectException(AtomsError::class);
        $this->expectExceptionMessageMatches('/ATOMS-E070.*DEPLOY_CALLBACK_URL/s');
        CloudflareTarget::resolve(AtomsJson::load($root . '/atoms.json'), 'production');
    }

    public function testDeploymentRejectsAnAmbientCallbackThatDiffersFromTheFile(): void
    {
        putenv(CloudflareTarget::CALLBACK_VAR . '=https://ambient.example.test/callback');

        $this->expectException(AtomsError::class);
        $this->expectExceptionMessageMatches('/ATOMS-E070.*ATOMS_CALLBACK_URL.*callback_url/s');
        CloudflareTarget::resolve($this->sampleApp(), 'production');
    }

    public function testDeploymentAcceptsAnAmbientCallbackThatAgreesWithTheFile(): void
    {
        putenv(CloudflareTarget::CALLBACK_VAR . '=https://acme.example.com');

        $target = CloudflareTarget::resolve($this->sampleApp(), 'production');

        self::assertSame('https://acme.example.com', $target->callbackUrl);
    }

    public function testDeploymentRejectsAnAmbientCallbackWhenTheFileIsMissing(): void
    {
        $root = $this->tempCopy('sample-app');
        $json = json_decode((string) file_get_contents($root . '/atoms.json'), true, 512, JSON_THROW_ON_ERROR);
        unset($json['callback_url']);
        file_put_contents($root . '/atoms.json', json_encode($json, JSON_THROW_ON_ERROR));
        putenv(CloudflareTarget::CALLBACK_VAR . '=https://ambient.example.test/callback');

        $this->expectException(AtomsError::class);
        $this->expectExceptionMessageMatches('/ATOMS-E070.*ATOMS_CALLBACK_URL/s');
        CloudflareTarget::resolve(AtomsJson::load($root . '/atoms.json'), 'production');
    }

    public function testDeploymentCallbackFlagIsRejected(): void
    {
        $this->expectException(AtomsError::class);
        $this->expectExceptionMessageMatches('/ATOMS-E070.*callback-url.*atoms dev/s');
        CloudflareTarget::resolve(
            $this->sampleApp(),
            'production',
            callbackUrl: 'https://flag.example.test/callback',
        );
    }

    public function testCallbackResolutionCanBeSkippedForOperationalTargets(): void
    {
        $root = $this->tempCopy('sample-app');
        $json = json_decode((string) file_get_contents($root . '/atoms.json'), true, 512, JSON_THROW_ON_ERROR);
        $json['callback_url']['production'] = '${UNSET_DEPLOYMENT_CALLBACK}';
        file_put_contents($root . '/atoms.json', json_encode($json, JSON_THROW_ON_ERROR));

        $target = CloudflareTarget::resolve(
            AtomsJson::load($root . '/atoms.json'),
            'production',
            resolveCallback: false,
        );

        self::assertNull($target->callbackUrl);
        self::assertSame([], $target->runtimeVars());
    }

    public function testLocalCallbackFlagOrEnvironmentWinsOverTheFile(): void
    {
        putenv(CloudflareTarget::CALLBACK_VAR . '=https://local.example.test/callback');
        $target = CloudflareTarget::resolve(
            $this->sampleApp(),
            'production',
            local: true,
        );
        self::assertSame('https://local.example.test/callback', $target->callbackUrl);

        $target = CloudflareTarget::resolve(
            $this->sampleApp(),
            'production',
            callbackUrl: 'https://local.example.test/callback',
            local: true,
        );
        self::assertSame('https://local.example.test/callback', $target->callbackUrl);
    }

    public function testLocalCallbackFlagAndEnvironmentMustAgree(): void
    {
        putenv(CloudflareTarget::CALLBACK_VAR . '=https://env.example.test/callback');

        $this->expectException(AtomsError::class);
        $this->expectExceptionMessageMatches('/ATOMS-E070.*callback-url.*ATOMS_CALLBACK_URL/s');
        CloudflareTarget::resolve(
            $this->sampleApp(),
            'production',
            callbackUrl: 'https://flag.example.test/callback',
            local: true,
        );
    }

    public function testLocalWithoutFlagOrEnvironmentFallsBackToTheFileIncludingIndirection(): void
    {
        putenv('LOCAL_CALLBACK_URL=https://file.example.test/callback');
        $root = $this->tempCopy('sample-app');
        $json = json_decode((string) file_get_contents($root . '/atoms.json'), true, 512, JSON_THROW_ON_ERROR);
        $json['callback_url']['production'] = '${LOCAL_CALLBACK_URL}';
        file_put_contents($root . '/atoms.json', json_encode($json, JSON_THROW_ON_ERROR));

        try {
            $target = CloudflareTarget::resolve(
                AtomsJson::load($root . '/atoms.json'),
                'production',
                local: true,
            );
            self::assertSame('https://file.example.test/callback', $target->callbackUrl);
        } finally {
            putenv('LOCAL_CALLBACK_URL');
        }
    }

    public function testLocalTreatsAnUnresolvedFileReferenceAsNoCallback(): void
    {
        // The file may name a variable only CI holds. `atoms dev` needs a
        // callback only for app()/dispatch(), and deploy merely warns when it
        // has none, so dev must not be the stricter of the two.
        $root = $this->tempCopy('sample-app');
        $json = json_decode((string) file_get_contents($root . '/atoms.json'), true, 512, JSON_THROW_ON_ERROR);
        $json['callback_url']['production'] = '${CI_ONLY_CALLBACK_URL}';
        file_put_contents($root . '/atoms.json', json_encode($json, JSON_THROW_ON_ERROR));
        putenv('CI_ONLY_CALLBACK_URL');

        $target = CloudflareTarget::resolve(AtomsJson::load($root . '/atoms.json'), 'production', local: true);

        self::assertNull($target->callbackUrl);
        self::assertArrayNotHasKey(CloudflareTarget::CALLBACK_VAR, $target->runtimeVars());
    }

    public function testDeploymentStillRequiresAnUnresolvedFileReference(): void
    {
        $root = $this->tempCopy('sample-app');
        $json = json_decode((string) file_get_contents($root . '/atoms.json'), true, 512, JSON_THROW_ON_ERROR);
        $json['callback_url']['production'] = '${CI_ONLY_CALLBACK_URL}';
        file_put_contents($root . '/atoms.json', json_encode($json, JSON_THROW_ON_ERROR));
        putenv('CI_ONLY_CALLBACK_URL');

        $this->expectException(AtomsError::class);
        $this->expectExceptionMessageMatches('/ATOMS-E070.*CI_ONLY_CALLBACK_URL/s');
        CloudflareTarget::resolve(AtomsJson::load($root . '/atoms.json'), 'production');
    }

    public function testAmbientConflictNamesTheReferenceTheFileDeclares(): void
    {
        // Telling the operator to declare "${ATOMS_CALLBACK_URL}" here would
        // repoint production at whatever this shell holds — usually a tunnel.
        $root = $this->tempCopy('sample-app');
        $json = json_decode((string) file_get_contents($root . '/atoms.json'), true, 512, JSON_THROW_ON_ERROR);
        $json['callback_url']['production'] = '${PRODUCTION_CALLBACK_URL}';
        file_put_contents($root . '/atoms.json', json_encode($json, JSON_THROW_ON_ERROR));
        putenv('PRODUCTION_CALLBACK_URL=https://acme.example.com/atoms/callback');
        putenv(CloudflareTarget::CALLBACK_VAR . '=https://tunnel.example.test/callback');

        try {
            CloudflareTarget::resolve(AtomsJson::load($root . '/atoms.json'), 'production');
            self::fail('expected ATOMS-E070');
        } catch (AtomsError $e) {
            self::assertStringContainsString('PRODUCTION_CALLBACK_URL', $e->getMessage());
            self::assertStringNotContainsString('declare "${ATOMS_CALLBACK_URL}"', $e->getMessage());
        } finally {
            putenv('PRODUCTION_CALLBACK_URL');
        }
    }

    public function testAnEmptyOrBlankFileCallbackMeansNoCallbackDeclared(): void
    {
        $root = $this->tempCopy('sample-app');
        $json = json_decode((string) file_get_contents($root . '/atoms.json'), true, 512, JSON_THROW_ON_ERROR);
        $json['callback_url']['production'] = '   ';
        file_put_contents($root . '/atoms.json', json_encode($json, JSON_THROW_ON_ERROR));

        $target = CloudflareTarget::resolve(AtomsJson::load($root . '/atoms.json'), 'production');

        self::assertNull($target->callbackUrl);
        self::assertArrayNotHasKey(CloudflareTarget::CALLBACK_VAR, $target->runtimeVars());
    }

    public function testLocalIgnoresAnAccountIdThatDiffersFromTheFile(): void
    {
        // `wrangler dev` runs workerd locally and never selects an account, so
        // a shell pointed at a different account must not block a dev server.
        putenv('CLOUDFLARE_ACCOUNT_ID=some-other-account');

        $target = CloudflareTarget::resolve($this->sampleApp(), 'production', local: true);

        self::assertNotSame('', $target->accountId);
    }

    public function testDeploymentStillRejectsAnAccountIdThatDiffersFromTheFile(): void
    {
        putenv('CLOUDFLARE_ACCOUNT_ID=some-other-account');

        $this->expectException(AtomsError::class);
        $this->expectExceptionMessageMatches('/ATOMS-E070.*CLOUDFLARE_ACCOUNT_ID/s');
        CloudflareTarget::resolve($this->sampleApp(), 'production');
    }

    public function testRuntimeVersionMatchesTheStamp(): void
    {
        $dir = $this->freshDir();
        file_put_contents($dir . '/' . RuntimeStamp::FILE, json_encode(['version' => RuntimeVersion::VERSION], JSON_THROW_ON_ERROR));
        $target = CloudflareTarget::resolve($this->sampleApp(), 'production', 'token', $dir);

        $target->assertRuntimeVersion();
        self::assertTrue(true);
    }

    public function testRuntimeVersionMismatchIsE108WithTheExactUpgradeCommand(): void
    {
        $config = $this->sampleApp();
        $dir = $config->rootDir . '/atoms-worker-skewed';
        mkdir($dir);
        try {
            file_put_contents($dir . '/' . RuntimeStamp::FILE, json_encode(['version' => '0.0.1-other'], JSON_THROW_ON_ERROR));
            $target = CloudflareTarget::resolve($config, 'production', 'token', 'atoms-worker-skewed');

            $target->assertRuntimeVersion();
            self::fail('expected ATOMS-E108');
        } catch (AtomsError $e) {
            self::assertStringContainsString('ATOMS-E108', $e->getMessage());
            self::assertStringContainsString('0.0.1-other', $e->getMessage());
            // The command is version-pinned to this CLI and names the directory.
            self::assertStringContainsString(RuntimeVersion::upgradeCommand($dir), $e->getMessage());
        } finally {
            @unlink($dir . '/' . RuntimeStamp::FILE);
            @rmdir($dir);
        }
    }

    /**
     * The scaffold and upgrade commands are printed for a human to paste, and
     * --worker-dir exists precisely for unusual locations — which may hold a
     * space or a shell metacharacter.
     */
    public function testPrintedCommandsQuoteADirectoryThatNeedsIt(): void
    {
        self::assertStringEndsWith(' init atoms-worker', RuntimeVersion::scaffoldCommand());
        self::assertStringEndsWith(' upgrade infra/atoms-worker', RuntimeVersion::upgradeCommand('infra/atoms-worker'));
        self::assertStringEndsWith(" upgrade 'my dir/it'\\''s'", RuntimeVersion::upgradeCommand("my dir/it's"));
        self::assertStringEndsWith(" upgrade 'a;rm -rf b'", RuntimeVersion::upgradeCommand('a;rm -rf b'));
    }

    public function testAnUnreadableStampIsE076NotE108(): void
    {
        $dir = $this->freshDir();
        file_put_contents($dir . '/' . RuntimeStamp::FILE, '{not json');
        $target = CloudflareTarget::resolve($this->sampleApp(), 'production', 'token', $dir);

        $this->expectException(AtomsError::class);
        $this->expectExceptionMessageMatches('/ATOMS-E076/');
        $target->assertRuntimeVersion();
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
