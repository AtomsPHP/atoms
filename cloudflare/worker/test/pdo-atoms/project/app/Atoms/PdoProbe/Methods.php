<?php

declare(strict_types=1);

namespace App\Atoms\PdoProbe;

use Atoms\AtomMethods;

final class Methods extends AtomMethods
{
    public function ping(): string
    {
        return 'pong';
    }
}
