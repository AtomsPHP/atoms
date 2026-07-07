<?php

declare(strict_types=1);

namespace Atoms\PHPStan\Tests\Fixtures\CallSite;

use Atoms\Atom;

final class GreeterAtom extends Atom
{
    public function greet(string $name): string
    {
        return 'hi ' . $name;
    }

    private function secret(): void
    {
    }
}
