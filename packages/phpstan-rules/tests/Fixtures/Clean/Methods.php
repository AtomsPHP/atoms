<?php

declare(strict_types=1);

namespace Atoms\PHPStan\Tests\Fixtures\Clean;

use Atoms\AtomMethods;

final class Methods extends AtomMethods
{
    public function getPlayer(string $id): PlayerSnapshot
    {
        return new PlayerSnapshot($id, 'Ada', 1500);
    }
}
