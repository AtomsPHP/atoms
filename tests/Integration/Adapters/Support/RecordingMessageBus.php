<?php

declare(strict_types=1);

namespace Atoms\Tests\Integration\Adapters\Support;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * In-test Messenger bus recorder for SymfonyHost: never dispatches to a real
 * transport, just records every message handed to it — the Symfony analogue
 * of {@see RecordingQueueBridge} one layer up (records the AtomJobMessage
 * envelope {@see \Atoms\Symfony\Messenger\MessengerQueueBridge} wraps a
 * dispatched AtomJob in, not the AtomJob itself).
 *
 * Deliberately duplicated from packages/symfony/tests/Support/RecordingMessageBus.php
 * rather than imported: package test namespaces are not cross-imported across
 * this monorepo (each package's tests/ is that package's own world), and this
 * suite lives outside every package under tests/Integration/Adapters/ — same
 * rule {@see FakePsr18Client}'s docblock states for its own duplication.
 */
final class RecordingMessageBus implements MessageBusInterface
{
    /** @var list<object> */
    public array $dispatched = [];

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $this->dispatched[] = $message;

        return new Envelope($message, $stamps);
    }
}
