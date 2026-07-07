<?php

declare(strict_types=1);

namespace Atoms\PHPStan\Tests\Fixtures\Clean;

use Atoms\AtomJob;

final class RecordResult extends AtomJob
{
    public function __construct(
        public readonly string $playerId,
        public readonly \DateTimeImmutable $recordedAt,
    ) {
    }
}
