<?php

declare(strict_types=1);

namespace Atoms\Core\Tests\Fixtures;

use Atoms\Serialization\Payload;

final class Timestamped implements Payload
{
    public function __construct(
        public readonly string $label,
        public readonly \DateTimeImmutable $at,
    ) {
    }
}
