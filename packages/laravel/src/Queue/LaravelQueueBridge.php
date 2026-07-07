<?php

declare(strict_types=1);

namespace Atoms\Laravel\Queue;

use Atoms\AtomJob;
use Atoms\Client\Callback\QueueBridge;
use Illuminate\Contracts\Bus\Dispatcher;

/**
 * Hands an inbound {@see AtomJob} to the host application's existing queue
 * (Horizon, database, SQS, whatever it's already configured to use) by
 * wrapping it in a {@see AtomJobEnvelope} and dispatching it as an ordinary
 * `ShouldQueue` job.
 */
final class LaravelQueueBridge implements QueueBridge
{
    public function __construct(private readonly Dispatcher $bus)
    {
    }

    public function enqueue(AtomJob $job): void
    {
        $this->bus->dispatch(AtomJobEnvelope::fromAtomJob($job));
    }
}
