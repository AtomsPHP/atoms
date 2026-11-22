<?php

declare(strict_types=1);

namespace Atoms\Cli\Tests\Cloudflare;

use Atoms\Cli\Cloudflare\CloudflareTarget;
use Atoms\Cli\Cloudflare\RuntimeStamp;
use Atoms\Cli\Cloudflare\WranglerBinary;
use Atoms\Cli\Config\AtomsJson;
use Atoms\Cli\Process\ProcessResult;
use Atoms\Cli\Process\SymfonyProcessRunner;
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
            $json['environments']['staging']['callback_url'] = '${DEPLOY_CALLBACK_URL}';
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
        foreach (array_keys($json['environments']) as $name) {
            $json['environments'][$name]['callback_url'] = '';
        }
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
        // CLOUDFLARE_ACCOUNT_ID is unset here, so the file entry answers.
        // The environment outranks the file, but only when it holds a value —
        // an absent variable is not a source.
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

    public function testAccountIdComesFromTheEnvironmentWhenTheFileOmitsIt(): void
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

    public function testAccountIdTakesTheEnvironmentOverTheFile(): void
    {
        putenv('CLOUDFLARE_ACCOUNT_ID=cf-account-1234');
        $target = CloudflareTarget::resolve($this->sampleApp(), 'production', 'token');
        self::assertSame('cf-account-1234', $target->accountId);

        // One order, no comparison: the fixture's file value says
        // cf-account-1234, and CLOUDFLARE_ACCOUNT_ID overrides it silently.
        putenv('CLOUDFLARE_ACCOUNT_ID=other-account');
        $target = CloudflareTarget::resolve($this->sampleApp(), 'production', 'token');
        self::assertSame('other-account', $target->accountId);
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

    public function testMalformedCallbackReferenceIsE070(): void
    {
        $root = $this->tempCopy('sample-app');
        $json = json_decode((string) file_get_contents($root . '/atoms.json'), true, 512, JSON_THROW_ON_ERROR);
        $json['environments']['production']['callback_url'] = '${NOT-A_VALID_NAME}';
        file_put_contents($root . '/atoms.json', json_encode($json, JSON_THROW_ON_ERROR));

        $this->expectException(AtomsError::class);
        $this->expectExceptionMessageMatches('/ATOMS-E070.*callback_url/s');
        CloudflareTarget::resolve(AtomsJson::load($root . '/atoms.json'), 'production');
    }

    public function testCallbackReferenceMustResolveToANonEmptyEnvironmentValue(): void
    {
        $root = $this->tempCopy('sample-app');
        $json = json_decode((string) file_get_contents($root . '/atoms.json'), true, 512, JSON_THROW_ON_ERROR);
        $json['environments']['production']['callback_url'] = '${DEPLOY_CALLBACK_URL}';
        file_put_contents($root . '/atoms.json', json_encode($json, JSON_THROW_ON_ERROR));
        putenv('DEPLOY_CALLBACK_URL=');

        $this->expectException(AtomsError::class);
        $this->expectExceptionMessageMatches('/ATOMS-E070.*DEPLOY_CALLBACK_URL/s');
        CloudflareTarget::resolve(AtomsJson::load($root . '/atoms.json'), 'production');
    }

    public function testDeploymentTakesTheEnvironmentCallbackOverTheFile(): void
    {
        putenv(CloudflareTarget::CALLBACK_VAR . '=https://ambient.example.test/callback');

        $target = CloudflareTarget::resolve($this->sampleApp(), 'production');

        self::assertSame('https://ambient.example.test/callback', $target->callbackUrl);
    }

    public function testDeploymentTakesTheEnvironmentCallbackEvenWhenItMatchesTheFile(): void
    {
        putenv(CloudflareTarget::CALLBACK_VAR . '=https://acme.example.com');

        $target = CloudflareTarget::resolve($this->sampleApp(), 'production');

        self::assertSame('https://acme.example.com', $target->callbackUrl);
    }

    public function testDeploymentUsesTheEnvironmentCallbackWhenTheFileHasNone(): void
    {
        $root = $this->tempCopy('sample-app');
        $json = json_decode((string) file_get_contents($root . '/atoms.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach (array_keys($json['environments']) as $name) {
            $json['environments'][$name]['callback_url'] = '';
        }
        file_put_contents($root . '/atoms.json', json_encode($json, JSON_THROW_ON_ERROR));
        putenv(CloudflareTarget::CALLBACK_VAR . '=https://ambient.example.test/callback');

        $target = CloudflareTarget::resolve(AtomsJson::load($root . '/atoms.json'), 'production');

        self::assertSame('https://ambient.example.test/callback', $target->callbackUrl);
    }

    public function testDeploymentCallbackFlagOverridesTheFile(): void
    {
        // Typing the flag is a deliberate act scoped to one invocation, so it
        // is the operator's final say — as with `terraform -var` and
        // `wrangler --var`. It is the nearest of the three sources.
        $target = CloudflareTarget::resolve(
            $this->sampleApp(),
            'production',
            callbackUrl: 'https://flag.example.test/callback',
        );

        self::assertSame('https://flag.example.test/callback', $target->callbackUrl);
    }

    public function testDeploymentCallbackFlagAlsoOutranksTheEnvironment(): void
    {
        putenv(CloudflareTarget::CALLBACK_VAR . '=https://ambient.example.test/callback');

        $target = CloudflareTarget::resolve(
            $this->sampleApp(),
            'production',
            callbackUrl: 'https://flag.example.test/callback',
        );

        // Same order as everywhere else: flag, then environment, then file.
        // Without the flag this variable would have supplied the callback.
        self::assertSame('https://flag.example.test/callback', $target->callbackUrl);
    }

    public function testCallbackResolutionCanBeSkippedForOperationalTargets(): void
    {
        $root = $this->tempCopy('sample-app');
        $json = json_decode((string) file_get_contents($root . '/atoms.json'), true, 512, JSON_THROW_ON_ERROR);
        $json['environments']['production']['callback_url'] = '${UNSET_DEPLOYMENT_CALLBACK}';
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

    public function testLocalCallbackFlagOutranksTheEnvironment(): void
    {
        putenv(CloudflareTarget::CALLBACK_VAR . '=https://env.example.test/callback');

        $target = CloudflareTarget::resolve(
            $this->sampleApp(),
            'production',
            callbackUrl: 'https://flag.example.test/callback',
            local: true,
        );

        self::assertSame('https://flag.example.test/callback', $target->callbackUrl);
    }

    public function testLocalWithoutFlagOrEnvironmentFallsBackToTheFileIncludingIndirection(): void
    {
        putenv('LOCAL_CALLBACK_URL=https://file.example.test/callback');
        $root = $this->tempCopy('sample-app');
        $json = json_decode((string) file_get_contents($root . '/atoms.json'), true, 512, JSON_THROW_ON_ERROR);
        $json['environments']['production']['callback_url'] = '${LOCAL_CALLBACK_URL}';
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
        $json['environments']['production']['callback_url'] = '${CI_ONLY_CALLBACK_URL}';
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
        $json['environments']['production']['callback_url'] = '${CI_ONLY_CALLBACK_URL}';
        file_put_contents($root . '/atoms.json', json_encode($json, JSON_THROW_ON_ERROR));
        putenv('CI_ONLY_CALLBACK_URL');

        $this->expectException(AtomsError::class);
        $this->expectExceptionMessageMatches('/ATOMS-E070.*CI_ONLY_CALLBACK_URL/s');
        CloudflareTarget::resolve(AtomsJson::load($root . '/atoms.json'), 'production');
    }

    public function testAnEnvironmentCallbackOutranksAFileReferenceWithoutExpandingIt(): void
    {
        // The environment is nearer than the file, so a declared ${VAR} is not
        // even consulted — and cannot fail for being unset.
        $root = $this->tempCopy('sample-app');
        $json = json_decode((string) file_get_contents($root . '/atoms.json'), true, 512, JSON_THROW_ON_ERROR);
        $json['environments']['production']['callback_url'] = '${UNSET_PRODUCTION_CALLBACK_URL}';
        file_put_contents($root . '/atoms.json', json_encode($json, JSON_THROW_ON_ERROR));
        putenv('UNSET_PRODUCTION_CALLBACK_URL');
        putenv(CloudflareTarget::CALLBACK_VAR . '=https://ambient.example.test/callback');

        $target = CloudflareTarget::resolve(AtomsJson::load($root . '/atoms.json'), 'production');

        self::assertSame('https://ambient.example.test/callback', $target->callbackUrl);
    }

    public function testAnEmptyOrBlankFileCallbackMeansNoCallbackDeclared(): void
    {
        $root = $this->tempCopy('sample-app');
        $json = json_decode((string) file_get_contents($root . '/atoms.json'), true, 512, JSON_THROW_ON_ERROR);
        $json['environments']['production']['callback_url'] = '   ';
        file_put_contents($root . '/atoms.json', json_encode($json, JSON_THROW_ON_ERROR));

        $target = CloudflareTarget::resolve(AtomsJson::load($root . '/atoms.json'), 'production');

        self::assertNull($target->callbackUrl);
        self::assertArrayNotHasKey(CloudflareTarget::CALLBACK_VAR, $target->runtimeVars());
    }

    public function testAccountIdPrecedenceIsTheSameLocallyAndOnDeploy(): void
    {
        putenv('CLOUDFLARE_ACCOUNT_ID=some-other-account');

        foreach ([true, false] as $local) {
            $target = CloudflareTarget::resolve($this->sampleApp(), 'production', local: $local);
            self::assertSame('some-other-account', $target->accountId);
        }
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

    /**
     * deploy and dev derive a per-environment config from the user's file,
     * and the CLI reads JSON only; a wrangler.toml is refused with the fix
     * named, rather than treated as a missing file.
     */
    public function testWorkerDirWithOnlyAWranglerTomlIsE076NamingTheConversion(): void
    {
        $dir = $this->freshDir();
        file_put_contents($dir . '/wrangler.toml', "name = \"w\"\n");
        $target = CloudflareTarget::resolve($this->sampleApp(), 'production', 'token', $dir);

        $this->expectException(AtomsError::class);
        $this->expectExceptionMessageMatches('/ATOMS-E076.*convert it to wrangler\.jsonc/');
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

    private function realWorkerDir(): string
    {
        $workerDir = \dirname(__DIR__, 4) . '/cloudflare/worker';
        if (!is_file($workerDir . '/' . CloudflareTarget::WORKER_CONFIG_MODULE)) {
            self::markTestSkipped('cloudflare/worker/src/config.js is not present in this checkout.');
        }
        if ((new SymfonyProcessRunner())->which('node') === null) {
            self::markTestSkipped('node is not on PATH; callback URL validation delegates to the Worker runtime.');
        }

        return $workerDir;
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validCallbackUrlProvider(): iterable
    {
        yield 'https' => ['https://app.example.com/atoms/callback'];
        yield 'https uppercase with port and query' => ['HTTPS://APP.EXAMPLE.COM:8443/atoms/callback?v=1'];
        yield 'https idn host' => ['https://münchen.example/callback'];
        yield 'https ipv6' => ['https://[2001:db8::1]:8443/atoms/callback'];
        yield 'http 127.0.0.1' => ['http://127.0.0.1:8000/atoms/callback'];
        yield 'http localhost' => ['http://localhost:8000/atoms/callback'];
        yield 'http uppercase localhost' => ['HTTP://LOCALHOST/atoms/callback'];
        yield 'http ipv6 loopback' => ['http://[::1]:8000/atoms/callback'];
        yield 'http expanded ipv6 loopback' => ['http://[0:0:0:0:0:0:0:1]:8000/atoms/callback'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('validCallbackUrlProvider')]
    public function testValidResolvedCallbackUrlsAreAcceptedByWorkerValidator(string $url): void
    {
        $target = CloudflareTarget::resolve(
            $this->sampleApp(),
            'production',
            workerDir: $this->realWorkerDir(),
            callbackUrl: $url,
        );

        $target->assertValidCallbackUrl(new SymfonyProcessRunner());

        self::assertSame($url, $target->callbackUrl);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidCallbackUrlProvider(): iterable
    {
        yield 'missing scheme' => ['missing-scheme', 'not a valid absolute URL'];
        yield 'relative path' => ['/atoms/callback', 'not a valid absolute URL'];
        yield 'protocol relative' => ['//example.com/atoms/callback', 'not a valid absolute URL'];
        yield 'numeric tld' => ['https://service.123/callback', 'not a valid absolute URL'];
        yield 'host and port without scheme' => ['localhost:8000/atoms/callback', 'must use "https:"'];
        yield 'empty host' => ['https://', 'not a valid absolute URL'];
        yield 'port out of range' => ['https://example.com:99999/atoms/callback', 'not a valid absolute URL'];
        yield 'invalid ipv4' => ['https://256.256.256.256/atoms/callback', 'not a valid absolute URL'];
        yield 'invalid ipv6' => ['https://[invalid]/atoms/callback', 'not a valid absolute URL'];
        yield 'non-loopback http' => ['http://example.com/atoms/callback', 'must use "https:"'];
        yield 'non-127.0.0.1 loopback subnet' => ['http://127.0.0.2:8000/atoms/callback', 'must use "https:"'];
        yield '0.0.0.0 http' => ['http://0.0.0.0:8000/atoms/callback', 'must use "https:"'];
        yield 'localhost trailing dot http' => ['http://localhost./atoms/callback', 'must use "https:"'];
        yield 'localhost subdomain http' => ['http://sub.localhost/atoms/callback', 'must use "https:"'];
        yield 'ipv4-mapped ipv6 loopback http' => ['http://[::ffff:127.0.0.1]/atoms/callback', 'must use "https:"'];
        yield 'ftp scheme' => ['ftp://example.com/atoms/callback', 'must use "https:"'];
        yield 'ws scheme on loopback' => ['ws://localhost:8000/atoms/callback', 'must use "https:"'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidCallbackUrlProvider')]
    public function testInvalidCallbackUrlFlagIsRejectedWithE070ByWorkerValidator(string $url, string $expectedFragment): void
    {
        $target = CloudflareTarget::resolve(
            $this->sampleApp(),
            'production',
            workerDir: $this->realWorkerDir(),
            callbackUrl: $url,
        );

        $this->expectException(AtomsError::class);
        $this->expectExceptionMessageMatches('/ATOMS-E070.*--callback-url.*' . preg_quote($expectedFragment, '/') . '/s');

        $target->assertValidCallbackUrl(new SymfonyProcessRunner());
    }

    public function testInvalidCallbackUrlFromEnvDotenvAndAtomsJsonAreAllRejected(): void
    {
        $workerDir = $this->realWorkerDir();
        $runner = new SymfonyProcessRunner();

        // Caller environment.
        putenv(CloudflareTarget::CALLBACK_VAR . '=http://example.com/cb');
        try {
            CloudflareTarget::resolve($this->sampleApp(), 'production', workerDir: $workerDir)
                ->assertValidCallbackUrl($runner);
            self::fail('expected ATOMS-E070 for caller environment');
        } catch (AtomsError $e) {
            self::assertStringContainsString('ATOMS-E070', $e->getMessage());
            self::assertStringContainsString('caller environment: ATOMS_CALLBACK_URL', $e->getMessage());
        } finally {
            putenv(CloudflareTarget::CALLBACK_VAR);
        }

        // .env.atoms.<env>.
        $root = $this->tempCopy('sample-app');
        file_put_contents($root . '/.env.atoms.production', "ATOMS_CALLBACK_URL=https://service.123/callback\n");
        try {
            CloudflareTarget::resolve(AtomsJson::load($root . '/atoms.json'), 'production', workerDir: $workerDir)
                ->assertValidCallbackUrl($runner);
            self::fail('expected ATOMS-E070 for dotenv');
        } catch (AtomsError $e) {
            self::assertStringContainsString('ATOMS-E070', $e->getMessage());
            self::assertStringContainsString('.env.atoms.production: ATOMS_CALLBACK_URL', $e->getMessage());
        }
        unlink($root . '/.env.atoms.production');

        // atoms.json literal.
        $json = json_decode((string) file_get_contents($root . '/atoms.json'), true, 512, JSON_THROW_ON_ERROR);
        $json['environments']['production']['callback_url'] = 'http://public.example.com/cb';
        file_put_contents($root . '/atoms.json', json_encode($json, JSON_THROW_ON_ERROR));
        $config = AtomsJson::load($root . '/atoms.json');
        try {
            CloudflareTarget::resolve($config, 'production', workerDir: $workerDir)
                ->assertValidCallbackUrl($runner);
            self::fail('expected ATOMS-E070 for atoms.json literal');
        } catch (AtomsError $e) {
            self::assertStringContainsString('ATOMS-E070', $e->getMessage());
            self::assertStringContainsString('environments.production.callback_url', $e->getMessage());
        }

        // Nearer valid override still wins without tripping on the invalid file entry,
        // and operational commands with resolveCallback: false still ignore it.
        $overridden = CloudflareTarget::resolve(
            $config,
            'production',
            workerDir: $workerDir,
            callbackUrl: 'https://münchen.example/callback',
        );
        $overridden->assertValidCallbackUrl($runner);
        self::assertSame('https://münchen.example/callback', $overridden->callbackUrl);

        $operational = CloudflareTarget::resolve($config, 'production', workerDir: $workerDir, resolveCallback: false);
        $operational->assertValidCallbackUrl($runner);
        self::assertNull($operational->callbackUrl);
    }

    public function testAssertValidCallbackUrlSkipsSubprocessWhenCallbackUrlIsOmitted(): void
    {
        $target = CloudflareTarget::resolve(
            $this->sampleApp(),
            'production',
            workerDir: $this->freshDir(),
            resolveCallback: false,
        );
        $runner = new FakeProcessRunner(onPath: []);

        $target->assertValidCallbackUrl($runner);

        self::assertSame([], $runner->runs);
    }

    public function testAssertValidCallbackUrlReportsMissingWorkerModuleMissingNodeAndUnexpectedFailureAsE076(): void
    {
        $emptyWorkerDir = $this->freshDir();
        $target = CloudflareTarget::resolve(
            $this->sampleApp(),
            'production',
            workerDir: $emptyWorkerDir,
            callbackUrl: 'https://app.example.com/cb',
        );

        try {
            $target->assertValidCallbackUrl(new FakeProcessRunner());
            self::fail('expected ATOMS-E076 when src/config.js is missing');
        } catch (AtomsError $e) {
            self::assertStringContainsString('ATOMS-E076', $e->getMessage());
            self::assertStringContainsString(CloudflareTarget::WORKER_CONFIG_MODULE, $e->getMessage());
        }

        mkdir($emptyWorkerDir . '/src', 0777, true);
        file_put_contents($emptyWorkerDir . '/src/config.js', '');

        try {
            $target->assertValidCallbackUrl(new FakeProcessRunner(onPath: []));
            self::fail('expected ATOMS-E076 when node is missing');
        } catch (AtomsError $e) {
            self::assertStringContainsString('ATOMS-E076', $e->getMessage());
            self::assertStringContainsString('node', $e->getMessage());
        }

        try {
            $target->assertValidCallbackUrl(new FakeProcessRunner(new ProcessResult(1, '', 'SyntaxError: boom')));
            self::fail('expected ATOMS-E076 when validator exits with non-2 error');
        } catch (AtomsError $e) {
            self::assertStringContainsString('ATOMS-E076', $e->getMessage());
            self::assertStringContainsString('SyntaxError: boom', $e->getMessage());
        }
    }
}
