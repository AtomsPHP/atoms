<?php

declare(strict_types=1);

namespace Atoms\Laravel\Tests;

use Atoms\Client\AtomsConfig;

/**
 * Exercises config/atoms.php's env() calls directly: real environment
 * variables, set before the app boots, must flow through to config('atoms.*')
 * — as opposed to AtomsServiceProviderTest's coverage of config() overrides
 * applied after boot.
 */
final class ConfigEnvOverrideTest extends TestCase
{
    /** A second valid secret: 32 bytes of 0x02. */
    private const PREVIOUS_SECRET = 'AgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgI=';

    protected function setUp(): void
    {
        putenv('ATOMS_SHARED_SECRET=' . self::SHARED_SECRET);
        putenv('ATOMS_SHARED_SECRET_PREVIOUS=' . self::PREVIOUS_SECRET);
        putenv('ATOMS_ENDPOINT=https://atoms.from-env.workers.dev');
        putenv('ATOMS_MAX_ATTEMPTS=9');

        parent::setUp();
    }

    protected function tearDown(): void
    {
        putenv('ATOMS_SHARED_SECRET');
        putenv('ATOMS_SHARED_SECRET_PREVIOUS');
        putenv('ATOMS_ENDPOINT');
        putenv('ATOMS_MAX_ATTEMPTS');

        parent::tearDown();
    }

    public function testEnvironmentVariablesFlowIntoConfig(): void
    {
        self::assertSame(self::SHARED_SECRET, config('atoms.shared_secret'));
        self::assertSame(self::PREVIOUS_SECRET, config('atoms.shared_secret_previous'));
        self::assertSame('https://atoms.from-env.workers.dev', config('atoms.endpoint'));
        self::assertSame(9, config('atoms.max_attempts'));
    }

    public function testBothSecretsReachAtomsConfig(): void
    {
        $config = $this->app->make(AtomsConfig::class);

        self::assertSame(self::SHARED_SECRET, $config->sharedSecret);
        self::assertSame(self::PREVIOUS_SECRET, $config->sharedSecretPrevious);
        self::assertCount(2, $config->callbackKeys(), 'the rotation overlap widens callback acceptance');
    }
}
