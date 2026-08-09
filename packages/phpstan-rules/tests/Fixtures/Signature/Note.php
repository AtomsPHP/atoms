<?php

declare(strict_types=1);

namespace Atoms\PHPStan\Tests\Fixtures\Signature;

use Atoms\Serialization\Payload;

final class Note implements Payload
{
    public function __construct(public readonly string $text)
    {
    }
}
