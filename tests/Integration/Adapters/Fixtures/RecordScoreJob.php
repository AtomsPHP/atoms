<?php

declare(strict_types=1);

namespace Atoms\Tests\Integration\Adapters\Fixtures;

use Atoms\AtomJob;

/**
 * The AtomJob every host's `job`-kind callback cases dispatch, exercising
 * {@see \Atoms\Client\Callback\CallbackKernel}'s constructor-parameter-name
 * reconstruction identically across every host.
 */
final class RecordScoreJob extends AtomJob
{
    public function __construct(
        public readonly string $playerId,
        public readonly int $score,
    ) {
    }
}
