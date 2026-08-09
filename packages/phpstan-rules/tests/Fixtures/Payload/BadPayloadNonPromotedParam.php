<?php

declare(strict_types=1);

namespace Atoms\PHPStan\Tests\Fixtures\Payload;

use Atoms\Serialization\Payload;

final class BadPayloadNonPromotedParam implements Payload
{
    public function __construct(
        public readonly string $id,
        string $unusedButNotPromoted,
    ) {
    }
}
