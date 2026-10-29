<?php

declare(strict_types=1);

namespace App\Atoms\GameRoom;

use Atoms\AtomMethods;

final class Methods extends AtomMethods
{
    public function appLabel(): string
    {
        return (string) config('app.name');
    }
}
