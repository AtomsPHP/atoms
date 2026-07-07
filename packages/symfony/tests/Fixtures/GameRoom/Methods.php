<?php

declare(strict_types=1);

namespace Atoms\Symfony\Tests\Fixtures\GameRoom;

use Atoms\AtomMethods;

/**
 * Convention-resolved Methods class (Fixtures\GameRoom -> Fixtures\GameRoom\Methods).
 */
final class Methods extends AtomMethods
{
    public function add(int $a, int $b): int
    {
        return $a + $b;
    }

    public function boom(): string
    {
        throw new \RuntimeException('customer code failed');
    }
}
