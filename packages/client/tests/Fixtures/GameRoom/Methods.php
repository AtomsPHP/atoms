<?php

declare(strict_types=1);

namespace Atoms\Client\Tests\Fixtures\GameRoom;

use Atoms\AtomMethods;
use Atoms\Client\Tests\Fixtures\PlayerSnapshot;

/**
 * Convention-resolved Methods class for the GameRoom Atom
 * (Fixtures\GameRoom → Fixtures\GameRoom\Methods).
 */
final class Methods extends AtomMethods
{
    public function add(int $a, int $b): int
    {
        return $a + $b;
    }

    public function describe(PlayerSnapshot $player): string
    {
        return sprintf('%s:%d', $player->name, $player->score);
    }

    public function boom(): string
    {
        throw new \RuntimeException('customer code failed at /var/www/secret.php');
    }
}
