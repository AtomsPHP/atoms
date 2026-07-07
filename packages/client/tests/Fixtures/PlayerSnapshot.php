<?php

declare(strict_types=1);

namespace Atoms\Client\Tests\Fixtures;

use Atoms\Serialization\Payload;

/**
 * A boundary DTO used to exercise typed denormalization.
 */
final class PlayerSnapshot implements Payload
{
    public function __construct(
        public readonly string $name,
        public readonly int $score,
    ) {
    }
}
