<?php

declare(strict_types=1);

namespace Atoms\Laravel\Tests\Fixtures;

/**
 * Stand-in Atom class for facade/fake tests. Not instantiated by the fake or
 * by AtomsManager (only its FQCN/basename is used as the wire type), so it
 * need not extend Atoms\Atom.
 */
final class GameRoom
{
    public function ping(): string
    {
        return 'pong';
    }
}
