<?php

declare(strict_types=1);

namespace Atoms\Examples\PlainPhp\Atoms;

use Atoms\Atom;

/**
 * A minimal illustrative Atom: one method that reaches into World B via
 * {@see self::app()}, the call `atoms/client`'s {@see \Atoms\Client\Callback\CallbackKernel}
 * answers on the way back in. It exists to give
 * `examples/plain-php/README.md` something concrete to point at, and to give
 * the adapter conformance suite a real Methods-class resolution to drive.
 */
final class GameRoom extends Atom
{
    public function greet(string $playerId): string
    {
        return $this->app()->displayName($playerId);
    }
}
