<?php

declare(strict_types=1);

namespace Atoms\Core\Tests\Fixtures;

use Atoms\Serialization\Payload;

final class WithDefaults implements Payload
{
    public function __construct(
        public readonly string $name,
        public readonly int $retries = 3,
        public readonly ?string $note = null,
    ) {
    }
}
