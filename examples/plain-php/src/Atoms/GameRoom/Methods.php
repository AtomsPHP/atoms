<?php

declare(strict_types=1);

namespace Atoms\Examples\PlainPhp\Atoms\GameRoom;

use Atoms\Attributes\MethodsFor;
use Atoms\AtomMethods;
use Atoms\Examples\PlainPhp\Atoms\GameRoom;

/**
 * World B for {@see GameRoom}. The `#[MethodsFor]` attribute is what lets a
 * single `$resolver->registerMethodsClass(Methods::class)` call resolve the
 * wire type `"GameRoom"` (the Atom class basename the platform sends) back to
 * this class — see `examples/plain-php/README.md` §Register your Methods
 * classes.
 */
#[MethodsFor(GameRoom::class)]
class Methods extends AtomMethods
{
    public function displayName(string $playerId): string
    {
        return sprintf('Player %s', $playerId);
    }
}
