<?php

declare(strict_types=1);

namespace Atoms\Symfony\Tests\Support;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * In-test Messenger bus recorder: never dispatches to a real transport.
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
