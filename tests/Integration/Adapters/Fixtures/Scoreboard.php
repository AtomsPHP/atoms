<?php

declare(strict_types=1);

namespace Atoms\Tests\Integration\Adapters\Fixtures;

/**
 * A plain class with no Atoms base type and no relation to the callback
 * wire protocol at all — the constructor dependency
 * {@see RankRoom\MethodsWithDependency} needs, so S6
 * ({@see \Atoms\Tests\Integration\Adapters\AdapterConformanceTestCase::testS6MethodsClassWithConstructorDependencyResolvesFromHostContainer()})
 * has something a bare `new $class()` genuinely cannot supply.
 */
final class Scoreboard
{
    public function format(int $score): string
    {
        return sprintf('Score: %d', $score);
    }
}
