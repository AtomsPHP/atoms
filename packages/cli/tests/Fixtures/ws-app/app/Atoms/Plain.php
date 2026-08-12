<?php

declare(strict_types=1);

namespace App\Atoms;

use Atoms\Atom;

/**
 * Extends Atoms\Atom directly and declares no WebSocket handler: the build can
 * see the whole hierarchy, so "websocket": false is a claim it is entitled to
 * make, and the Worker refuses GET /ws for this type before touching a DO.
 */
final class Plain extends Atom
{
    public function ping(): string
    {
        return 'pong';
    }
}
