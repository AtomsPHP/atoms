<?php

declare(strict_types=1);

namespace Atoms\Tests\Integration\Adapters\Fixtures;

use Atoms\Serialization\Payload;

/**
 * Boundary DTO the adapter conformance suite uses to prove a Methods-class
 * return value that is itself a {@see Payload} normalizes identically across
 * every host (case 2 in {@see \Atoms\Tests\Integration\Adapters\CallbackCases}).
 */
final class PlayerSnapshot implements Payload
{
    public function __construct(
        public readonly string $name,
        public readonly int $score,
    ) {
    }
}
