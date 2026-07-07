<?php

declare(strict_types=1);

namespace Atoms\Testing\Tests\Fixtures;

use Atoms\Serialization\Payload;

/**
 * A boundary DTO fixture: crosses dispatch() and app() calls, proving Payload
 * round-trip through the harness's serialization boundary.
 */
final class Score implements Payload
{
    public function __construct(
        public readonly int $value,
        public readonly string $tier,
    ) {
    }
}
