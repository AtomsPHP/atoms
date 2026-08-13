<?php

declare(strict_types=1);

namespace Atoms\Tests\Integration\Adapters\Support;

use Atoms\AtomJob;
use Atoms\Client\Callback\QueueBridge;

/**
 * Records every enqueued AtomJob instead of dispatching it anywhere. Used as
 * the queue wiring for every host so `queuedJobs()` is uniform across the
 * suite (see AGENTS.md's mission for T9a: "use the RecordingQueueBridge so
 * queuedJobs() is uniform").
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
