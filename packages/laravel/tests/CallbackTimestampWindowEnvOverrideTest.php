<?php

declare(strict_types=1);

namespace Atoms\Laravel\Tests;

/**
 * Exercises config/atoms.php's ATOMS_CALLBACK_TIMESTAMP_WINDOW env() call
 * directly: a real environment variable, set before the app boots, must flow
 * through to config('atoms.callback_timestamp_window') — mirrors
 * ConfigEnvOverrideTest's putenv()-in-setUp()/tearDown() pattern exactly.
 * CallbackTimestampWindowDefaultTest covers the unset-env default.
 */
final class CallbackTimestampWindowEnvOverrideTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('ATOMS_CALLBACK_TIMESTAMP_WINDOW=60');

        parent::setUp();
    }

    protected function tearDown(): void
    {
        putenv('ATOMS_CALLBACK_TIMESTAMP_WINDOW');

        parent::tearDown();
    }

    public function testEnvironmentVariableFlowsIntoConfig(): void
    {
        self::assertSame(60, config('atoms.callback_timestamp_window'));
    }
}
