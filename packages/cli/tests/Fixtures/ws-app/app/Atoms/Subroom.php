<?php

declare(strict_types=1);

namespace App\Atoms;

/**
 * Declares no handler of its own, and extends something other than
 * Atoms\Atom — so the generator cannot see whether the parent chain declares
 * one (here it does) and must OMIT the websocket key rather than guess. An
 * omitted key means "allowed" at runtime, which is the safe direction: the
 * runtime's own dispatch decides, instead of a build-time guess producing a
 * wrongful 501 on handlers that work.
 */
final class Subroom extends Roomish
{
    public function occupants(): int
    {
        return 0;
    }
}
