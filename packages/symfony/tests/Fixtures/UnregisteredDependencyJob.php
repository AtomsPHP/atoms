<?php

declare(strict_types=1);

namespace Atoms\Symfony\Tests\Fixtures;

use Atoms\AtomJob;

/**
 * An AtomJob whose handle() declares a required dependency not registered in
 * the container.
 */
final class UnregisteredDependencyJob extends AtomJob
{
    public function __construct(
        public readonly string $id,
    ) {
    }

    public function handle(UnboundService $missing): void
    {
        unset($missing);
    }
}
