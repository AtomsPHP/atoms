<?php

declare(strict_types=1);

namespace Atoms\Symfony\Tests\Fixtures\ConventionRoom;

use Atoms\AtomMethods;
use Atoms\Symfony\Tests\Fixtures\ScoreFormatter;

/**
 * Convention-named Methods class (no `#[MethodsFor]`) with a required
 * constructor dependency that `new $class()` cannot satisfy.
 */
final class Methods extends AtomMethods
{
    public function __construct(private readonly ScoreFormatter $formatter)
    {
    }

    public function describe(int $score): string
    {
        return $this->formatter->format($score);
    }
}
