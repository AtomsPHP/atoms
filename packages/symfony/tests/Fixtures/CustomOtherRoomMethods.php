<?php

declare(strict_types=1);

namespace Atoms\Symfony\Tests\Fixtures;

use Atoms\Attributes\MethodsFor;
use Atoms\AtomMethods;

#[MethodsFor(OtherRoom::class)]
final class CustomOtherRoomMethods extends AtomMethods
{
    public function greet(string $name): string
    {
        return 'hello ' . $name;
    }
}
