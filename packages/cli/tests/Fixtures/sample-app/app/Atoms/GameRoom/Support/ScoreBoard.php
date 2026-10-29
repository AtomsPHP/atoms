<?php

declare(strict_types=1);

namespace App\Atoms\GameRoom\Support;

use Ramsey\Uuid\Uuid;

/** Atom helper with an approved vendor dependency. */
final class ScoreBoard
{
    public function entryRef(): string
    {
        return Uuid::uuid4()->toString();
    }
}
