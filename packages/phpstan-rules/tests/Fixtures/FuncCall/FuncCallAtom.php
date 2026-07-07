<?php

declare(strict_types=1);

namespace Atoms\PHPStan\Tests\Fixtures\FuncCall;

use Atoms\Atom;

final class FuncCallAtom extends Atom
{
    public function bad(): void
    {
        env('FOO');
        serialize([1, 2]);
        unserialize('a:0:{}');
        config('app.name');
        now();
    }
}
