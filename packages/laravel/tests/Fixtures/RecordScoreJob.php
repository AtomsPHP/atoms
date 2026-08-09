<?php

declare(strict_types=1);

namespace Atoms\Laravel\Tests\Fixtures;

use Atoms\AtomJob;

/**
 * An AtomJob whose handle() records a visible side effect, so the queue
 * bridge test can assert reconstruction actually ran it (rather than just
 * asserting the envelope was pushed).
 */
final class RecordScoreJob extends AtomJob
{
    /** @var list<array{playerId: string, score: int}> */
    public static array $handled = [];

    public function __construct(
        public readonly string $playerId,
        public readonly int $score,
    ) {
    }

    public function handle(): void
    {
        self::$handled[] = ['playerId' => $this->playerId, 'score' => $this->score];
    }
}
