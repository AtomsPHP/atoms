<?php

declare(strict_types=1);

namespace Atoms\Examples\PlainPhp;

use Atoms\AtomJob;
use Atoms\Client\Callback\QueueBridge;

/**
 * The {@see QueueBridge} a no-framework host writes: appends every dispatched
 * {@see AtomJob} to an in-process list instead of a real queue. Good enough
 * for this demo and for tests; a production host should hand jobs to a real
 * queue (a database table it polls, a Redis list, whatever it already runs).
 */
class ArrayQueueBridge implements QueueBridge
{
    /** @var list<AtomJob> */
    private array $jobs = [];

    public function enqueue(AtomJob $job): void
    {
        $this->jobs[] = $job;
    }

    /**
     * @return list<AtomJob>
     */
    public function jobs(): array
    {
        return $this->jobs;
    }
}
