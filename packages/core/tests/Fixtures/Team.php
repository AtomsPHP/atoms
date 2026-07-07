<?php

declare(strict_types=1);

namespace Atoms\Core\Tests\Fixtures;

use Atoms\Serialization\Payload;

final class Team implements Payload
{
    /**
     * @param list<PlayerSnapshot> $players
     * @param array<string, int> $scores
     */
    public function __construct(
        public readonly string $name,
        public readonly PlayerSnapshot $captain,
        public readonly array $players,
        public readonly array $scores,
        public readonly Status $status,
        public readonly ?PlayerSnapshot $mascot = null,
    ) {
    }
}
