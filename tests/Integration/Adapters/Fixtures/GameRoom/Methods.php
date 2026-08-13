<?php

declare(strict_types=1);

namespace Atoms\Tests\Integration\Adapters\Fixtures\GameRoom;

use Atoms\AtomMethods;
use Atoms\Attributes\MethodsFor;
use Atoms\Tests\Integration\Adapters\Fixtures\GameRoom;
use Atoms\Tests\Integration\Adapters\Fixtures\PlayerSnapshot;

/**
 * The one Methods class every host in the adapter conformance suite
 * registers. `#[MethodsFor]` is what lets `MethodsResolver::registerMethodsClass()`
 * resolve the wire type `"GameRoom"` back to this class regardless of which
 * host's resolver is doing the resolving — see
 * {@see \Atoms\Tests\Integration\Adapters\CallbackCases}.
 */
#[MethodsFor(GameRoom::class)]
final class Methods extends AtomMethods
{
    public function add(int $a, int $b): int
    {
        return $a + $b;
    }

    public function snapshot(): PlayerSnapshot
    {
        return new PlayerSnapshot('ada', 7);
    }

    public function boom(): never
    {
        throw new \RuntimeException('boom');
    }
}
