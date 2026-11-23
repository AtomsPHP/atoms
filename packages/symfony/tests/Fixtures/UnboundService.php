<?php

declare(strict_types=1);

namespace Atoms\Symfony\Tests\Fixtures;

/**
 * Marker class deliberately never registered as a service, used to test
 * missing-dependency diagnostics on AtomJob::handle().
 */
final class UnboundService
{
}
