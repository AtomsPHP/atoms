<?php

declare(strict_types=1);

namespace Atoms\Core\Tests\Fixtures;

use Atoms\Serialization\Payload;

final class PlayerSnapshot implements Payload
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly int $elo,
    ) {
    }
}
