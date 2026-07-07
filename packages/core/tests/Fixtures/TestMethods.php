<?php

declare(strict_types=1);

namespace Atoms\Core\Tests\Fixtures;

use Atoms\AtomMethods;

final class TestMethods extends AtomMethods
{
    public function greeting(string $name): string
    {
        return "hello {$name}";
    }

    public function record(int $score, ?string $note, PlayerSnapshot $player, Status $status): array
    {
        return [$score, $note, $player, $status];
    }
}
