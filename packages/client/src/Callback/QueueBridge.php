<?php

declare(strict_types=1);

namespace Atoms\Client\Callback;

use Atoms\AtomJob;

/**
 * Adapter between an inbound `job` callback and the host application's queue.
 * The framework adapter (Laravel/Symfony) implements this to hand the
 * reconstructed {@see AtomJob} to its own dispatcher.
 */
interface QueueBridge
{
    public function enqueue(AtomJob $job): void;
}
