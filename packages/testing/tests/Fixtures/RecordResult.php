<?php

declare(strict_types=1);

namespace Atoms\Testing\Tests\Fixtures;

use Atoms\AtomJob;

/**
 * AtomJob fixture: promoted ctor properties include a Payload DTO and a
 * DateTimeImmutable, exercising both branches of the serialization algebra
 * when the harness reconstructs a dispatched job.
 */
final class RecordResult extends AtomJob
{
    public function __construct(
        public readonly string $user,
        public readonly int $points,
        public readonly Score $score,
        public readonly \DateTimeImmutable $recordedAt,
    ) {
    }
}
