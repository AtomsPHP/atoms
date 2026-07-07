<?php

declare(strict_types=1);

namespace Atoms\Client\Tests\Support;

use Atoms\AtomJob;
use Atoms\Client\Callback\QueueBridge;

/**
 * Records enqueued jobs for assertions.
 */
final class RecordingQueueBridge implements QueueBridge
{
    /** @var list<AtomJob> */
    public array $jobs = [];

    public function enqueue(AtomJob $job): void
    {
        $this->jobs[] = $job;
    }
}
