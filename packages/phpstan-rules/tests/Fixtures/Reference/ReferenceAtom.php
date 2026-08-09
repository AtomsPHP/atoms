<?php

declare(strict_types=1);

namespace Atoms\PHPStan\Tests\Fixtures\Reference;

use Atoms\Atom;
use Illuminate\Support\Facades\Auth;

final class ReferenceAtom extends Atom
{
    public function newIllegal(): void
    {
        new \Illuminate\Support\Collection();
        new \App\Models\User();
    }

    public function staticIllegal(): void
    {
        \Illuminate\Support\Str::random();
        Auth::user();
    }

    public function classConstIllegal(): string
    {
        return \App\Models\User::class;
    }

    public function instanceofIllegal(object $x): bool
    {
        return $x instanceof \App\Models\User;
    }
}
