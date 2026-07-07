<?php

declare(strict_types=1);

namespace Atoms\Client\Tests\Fixtures;

/**
 * A plain class that is NOT an AtomJob — used to assert the job kind rejects it.
 */
final class NotAJob
{
    public function __construct(public readonly string $whatever = '')
    {
    }
}
