<?php

declare(strict_types=1);

namespace Atoms\Client\Tests\Fixtures;

use Atoms\AtomJob;

/**
 * An AtomJob whose promoted constructor properties are the dispatch contract.
 */
final class SendWelcomeJob extends AtomJob
{
    public function __construct(
        public readonly string $playerId,
        public readonly int $roomSize,
        public readonly bool $vip = false,
    ) {
    }
}
