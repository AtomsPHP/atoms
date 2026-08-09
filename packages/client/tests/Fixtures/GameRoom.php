<?php

declare(strict_types=1);

namespace Atoms\Client\Tests\Fixtures;

/**
 * Stand-in Atom class. The client only reflects declared return types on this
 * class; it is never instantiated, so it need not extend Atoms\Atom.
 */
final class GameRoom
{
    public function snapshot(string $player): PlayerSnapshot
    {
        return new PlayerSnapshot($player, 0);
    }

    public function ping(): string
    {
        return 'pong';
    }

    public function tally(): int
    {
        return 0;
    }
}
