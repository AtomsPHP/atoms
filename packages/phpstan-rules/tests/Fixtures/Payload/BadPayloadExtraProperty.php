<?php

declare(strict_types=1);

namespace Atoms\PHPStan\Tests\Fixtures\Payload;

use Atoms\Serialization\Payload;

final class BadPayloadExtraProperty implements Payload
{
    private string $extra = '';

    public function __construct(public readonly string $id)
    {
    }
}
