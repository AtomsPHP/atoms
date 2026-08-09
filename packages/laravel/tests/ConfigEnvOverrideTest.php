<?php

declare(strict_types=1);

namespace Atoms\Laravel\Tests;

/**
 * Exercises config/atoms.php's env() calls directly: real environment
 * variables, set before the app boots, must flow through to config('atoms.*')
 * — as opposed to AtomsServiceProviderTest's coverage of config() overrides
 * applied after boot.
 */
final class ConfigEnvOverrideTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('ATOMS_API_KEY=from-env-key');
        putenv('ATOMS_ENDPOINT=https://from-env.atoms.cloud');
        putenv('ATOMS_MAX_ATTEMPTS=9');

        parent::setUp();
    }

    protected function tearDown(): void
    {
        putenv('ATOMS_API_KEY');
        putenv('ATOMS_ENDPOINT');
        putenv('ATOMS_MAX_ATTEMPTS');

        parent::tearDown();
    }

    public function testEnvironmentVariablesFlowIntoConfig(): void
    {
        self::assertSame('from-env-key', config('atoms.api_key'));
        self::assertSame('https://from-env.atoms.cloud', config('atoms.endpoint'));
        self::assertSame(9, config('atoms.max_attempts'));
    }
}
