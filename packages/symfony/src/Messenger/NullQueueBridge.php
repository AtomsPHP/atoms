<?php

declare(strict_types=1);

namespace Atoms\Symfony\Messenger;

use Atoms\AtomJob;
use Atoms\Client\Callback\QueueBridge;

/**
 * @internal Phase 1 layering test — not yet a supported product
 *
 * The default QueueBridge when the app has no Messenger bus configured (or
 * symfony/messenger isn't installed). Throws with a clear fix on first use
 * rather than silently dropping dispatched jobs.
 */
final class NullQueueBridge implements QueueBridge
{
    public function enqueue(AtomJob $job): void
    {
        throw new \RuntimeException(sprintf(
            'No QueueBridge is configured for Atoms callbacks (tried to enqueue %s). '
            . 'Install symfony/messenger and register a message bus service so '
            . 'Atoms\\Symfony\\Messenger\\MessengerQueueBridge is wired automatically, '
            . 'or implement %s yourself and alias it to that service id.',
            $job::class,
            QueueBridge::class,
        ));
    }
}
