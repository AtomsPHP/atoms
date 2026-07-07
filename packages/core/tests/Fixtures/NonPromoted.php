<?php

declare(strict_types=1);

namespace Atoms\Core\Tests\Fixtures;

use Atoms\Serialization\Payload;

/**
 * A Payload whose constructor param is NOT a promoted property — normalizing it
 * must fail with ATOMS-E023.
 */
final class NonPromoted implements Payload
{
    public readonly string $value;

    public function __construct(string $value)
    {
        $this->value = $value;
    }
}
