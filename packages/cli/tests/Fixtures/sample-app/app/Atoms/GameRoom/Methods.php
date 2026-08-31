<?php

declare(strict_types=1);

namespace App\Atoms\GameRoom;

use App\Atoms\Shared\PlayerSnapshot;
use Atoms\AtomMethods;

/**
 * App-side. Stays in the monolith; the Atom reaches it via $this->app(). Its
 * signatures are the contract the build validates.
 */
final class Methods extends AtomMethods
{
    public function getPlayer(string $id): PlayerSnapshot
    {
        return new PlayerSnapshot($id, 'Ada', 1500);
    }
}
