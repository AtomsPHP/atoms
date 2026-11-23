<?php

declare(strict_types=1);

namespace Atoms\Tests\Integration\Adapters\Fixtures\ConventionRoom;

use Atoms\AtomMethods;
use Atoms\Tests\Integration\Adapters\Fixtures\Scoreboard;

/**
 * Convention-named Methods class with a constructor dependency and no
 * `#[MethodsFor]` attribute.
 */
final class Methods extends AtomMethods
{
    public function __construct(private readonly Scoreboard $scoreboard)
    {
    }

    public function rank(int $n): string
    {
        return $this->scoreboard->format($n);
    }
}
