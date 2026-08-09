<?php

declare(strict_types=1);

namespace Atoms\Testing;

use Atoms\Websocket\Connection;

/**
 * A recording {@see Connection} fake for exercising `onConnect`/`onMessage`/
 * `onDisconnect` in-process, with no real socket. Every `send()` call is
 * captured in order; `close()` records the code/reason and flips
 * {@see isClosed()}.
 */
final class FakeConnection implements Connection
{
    private readonly string $connId;

    /** @var list<string> */
    private array $sent = [];

    private bool $closed = false;

    private int $closeCode = 1000;

    private string $closeReason = '';

    public function __construct(?string $id = null)
    {
        $this->connId = $id ?? ('fake-conn-' . bin2hex(random_bytes(6)));
    }

    public function id(): string
    {
        return $this->connId;
    }

    public function send(string $payload): void
    {
        $this->sent[] = $payload;
    }

    public function close(int $code = 1000, string $reason = ''): void
    {
        $this->closed = true;
        $this->closeCode = $code;
        $this->closeReason = $reason;
    }

    /**
     * @return list<string> every payload passed to send(), in order
     */
    public function sent(): array
    {
        return $this->sent;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function closeCode(): int
    {
        return $this->closeCode;
    }

    public function closeReason(): string
    {
        return $this->closeReason;
    }
}
