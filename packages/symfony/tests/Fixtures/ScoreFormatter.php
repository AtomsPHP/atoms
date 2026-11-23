<?php

declare(strict_types=1);

namespace Atoms\Symfony\Tests\Fixtures;

/**
 * Plain app service injected into convention-named Methods and AtomJob tests,
 * proving container-based autowiring satisfies real constructor/handle()
 * dependencies that a bare `new $class()` could not supply.
 */
final class ScoreFormatter
{
    public function __construct(private readonly string $prefix = 'Score')
    {
    }

    public function format(int $score): string
    {
        return sprintf('%s: %d', $this->prefix, $score);
    }
}
