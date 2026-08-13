<?php

declare(strict_types=1);

namespace Atoms\Client\Callback;

use Atoms\AtomJob;
use Atoms\Errors\AtomsError;
use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;

/**
 * The default queue port when a host wires no {@see QueueBridge} of its own.
 * Rather than silently dropping a dispatched job, it fails loudly with the
 * catalog code {@see ErrorCode::NoQueueBridgeConfigured}, so a missing wire-up
 * surfaces as a clear callback error instead of a job vanishing.
 *
 * The optional `$hint` lets a framework adapter append host-specific remedy
 * text (e.g. how to bind its own bridge) without putting framework
 * vocabulary into atoms/client itself.
 */
final class NullQueueBridge implements QueueBridge
{
    public function __construct(private readonly ?string $hint = null)
    {
    }

    public function enqueue(AtomJob $job): void
    {
        $message = ErrorCatalog::format(ErrorCode::NoQueueBridgeConfigured, ['job' => $job::class]);

        if ($this->hint !== null) {
            $message .= ' ' . $this->hint;
        }

        throw new AtomsError(ErrorCode::NoQueueBridgeConfigured, $message);
    }
}
