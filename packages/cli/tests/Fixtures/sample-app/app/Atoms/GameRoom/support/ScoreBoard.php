<?php

declare(strict_types=1);

namespace App\Atoms\GameRoom\Support;

use Ramsey\Uuid\Uuid;

/**
 * World A. A support class: ships with GameRoom, runs only in the Atoms
 * runtime, and may use approved vendor packages like Atom code.
 */
final class ScoreBoard
{
    public function entryRef(): string
    {
        return Uuid::uuid4()->toString();
    }
}
