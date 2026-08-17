<?php

declare(strict_types=1);

namespace App\Atoms;

/**
 * The same FQCN as `../GameRoom.php`, declared a second time — the shape a
 * half-finished move leaves behind. Sorting puts this file second, so it is
 * the declaration that survives in the FQCN index. A bundle would carry both
 * files and the guest would fatal on whichever loaded second, which is why
 * this is ATOMS-E002 and an error.
 */
final class GameRoom extends \Atoms\Atom
{
    public function join(): string
    {
        return 'ok';
    }
}
