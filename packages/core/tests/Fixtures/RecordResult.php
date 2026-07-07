<?php

declare(strict_types=1);

namespace Atoms\Core\Tests\Fixtures;

use Atoms\AtomJob;

final class RecordResult extends AtomJob
{
    public function __construct(
        public readonly string $gameId,
        public readonly int $score,
    ) {
    }
}
