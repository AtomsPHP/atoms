<?php

declare(strict_types=1);

namespace Atoms\Laravel\Tests;

/**
 * config('atoms.callback_timestamp_window') (the CallbackKernel constructor
 * arg 7 default that AtomsServiceProvider reads via
 * config('atoms.callback_timestamp_window', 300)) defaults to 300 seconds
 * when ATOMS_CALLBACK_TIMESTAMP_WINDOW is unset. See
 * CallbackTimestampWindowEnvOverrideTest for the env-override case.
 */
final class CallbackTimestampWindowDefaultTest extends TestCase
{
    public function testDefaultIsThreeHundredSeconds(): void
    {
        self::assertSame(300, config('atoms.callback_timestamp_window'));
    }
}
