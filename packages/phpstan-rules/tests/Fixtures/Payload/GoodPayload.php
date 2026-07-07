<?php

declare(strict_types=1);

namespace Atoms\PHPStan\Tests\Fixtures\Payload;

use Atoms\Serialization\Payload;

final class GoodPayload implements Payload
{
    public function __construct(
        public readonly string $id,
        public readonly int $score,
    ) {
    }
}
