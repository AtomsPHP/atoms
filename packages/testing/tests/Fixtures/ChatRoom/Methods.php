<?php

declare(strict_types=1);

namespace Atoms\Testing\Tests\Fixtures\ChatRoom;

use Atoms\AtomMethods;
use Atoms\Testing\Tests\Fixtures\Score;

/**
 * World B fixture. Named `Methods` at namespace `...Fixtures\ChatRoom` so the
 * harness's default convention (`{AtomFQCN}\Methods`) resolves it for
 * `Atoms\Testing\Tests\Fixtures\ChatRoom` with no explicit withMethods() call.
 */
final class Methods extends AtomMethods
{
    public function screen(string $text): string
    {
        return trim($text) === '' ? '[empty]' : strtoupper($text);
    }

    public function describe(Score $score, \DateTimeImmutable $at): string
    {
        return sprintf('%s@%d:%s', $score->tier, $score->value, $at->format('Y-m-d'));
    }
}
