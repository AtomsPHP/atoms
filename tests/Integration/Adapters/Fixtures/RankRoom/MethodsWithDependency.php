<?php

declare(strict_types=1);

namespace Atoms\Tests\Integration\Adapters\Fixtures\RankRoom;

use Atoms\AtomMethods;
use Atoms\Attributes\MethodsFor;
use Atoms\Tests\Integration\Adapters\Fixtures\RankRoom;
use Atoms\Tests\Integration\Adapters\Fixtures\Scoreboard;

/**
 * Unlike {@see \Atoms\Tests\Integration\Adapters\Fixtures\GameRoom\Methods},
 * this Methods class has a REAL constructor dependency — a {@see Scoreboard}
 * — that `new $class()` cannot satisfy.
 * {@see \Atoms\Client\Callback\CallbackKernel::instantiate()} can only build
 * this class by consulting the host's own PSR-11 container, which is exactly
 * the "Methods instantiation" port `docs/adapters.md` documents and which S6
 * ({@see \Atoms\Tests\Integration\Adapters\AdapterConformanceTestCase::testS6MethodsClassWithConstructorDependencyResolvesFromHostContainer()})
 * proves against every container-capable host.
 */
#[MethodsFor(RankRoom::class)]
final class MethodsWithDependency extends AtomMethods
{
    public function __construct(private readonly Scoreboard $scoreboard)
    {
    }

    public function rank(int $n): string
    {
        return $this->scoreboard->format($n);
    }
}
