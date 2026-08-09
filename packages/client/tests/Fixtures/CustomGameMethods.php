<?php

declare(strict_types=1);

namespace Atoms\Client\Tests\Fixtures;

use Atoms\Attributes\MethodsFor;
use Atoms\AtomMethods;

/**
 * A Methods class declared for GameRoom via attribute rather than convention.
 */
#[MethodsFor(GameRoom::class)]
final class CustomGameMethods extends AtomMethods
{
    public function hello(): string
    {
        return 'hi';
    }
}
