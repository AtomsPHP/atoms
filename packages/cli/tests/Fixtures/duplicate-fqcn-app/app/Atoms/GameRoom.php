<?php

declare(strict_types=1);

namespace App\Atoms;

/**
 * A perfectly ordinary Atom. `Legacy/GameRoom.php` declares the same FQCN, so
 * the FQCN index cannot hold both and this one — parsed first — is the copy it
 * drops. Discovery must still classify it as an Atom: the file is not
 * unclassifiable, and reporting it as such (ATOMS-E001) points the build at
 * the wrong file and never mentions the collision that caused it.
 */
final class GameRoom extends \Atoms\Atom
{
    public function join(): string
    {
        return 'ok';
    }
}
