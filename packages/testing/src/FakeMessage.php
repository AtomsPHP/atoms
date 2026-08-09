<?php

declare(strict_types=1);

namespace Atoms\Testing;

use Atoms\Websocket\Message;

/**
 * A test-authored inbound WebSocket frame, handed to `Atom::onMessage()` by
 * {@see AtomHarness::sendMessage()}.
 */
final class FakeMessage implements Message
{
    public function __construct(
        private readonly string $payload,
        private readonly bool $binary = false,
    ) {
    }

    public function payload(): string
    {
        return $this->payload;
    }

    public function isBinary(): bool
    {
        return $this->binary;
    }
}
