<?php

declare(strict_types=1);

namespace Atoms\Testing;

use Atoms\Websocket\Connection;
use Atoms\Websocket\JsonFrame;

/**
 * A recording {@see Connection} fake for exercising `onConnect`/`onMessage`/
 * `onDisconnect` in-process, with no real socket. Every `send()` call is
 * captured in order; `close()` records the code/reason and flips
 * {@see isClosed()}.
 *
 * `sendJson()` really encodes, so a payload outside the serialization algebra
 * fails in a test exactly as it would in the runtime. It records into both
 * {@see sent()} (as the encoded string, interleaved with raw sends in call
 * order) and {@see sentJson()} (decoded, for assertions).
 */
final class FakeConnection implements Connection
{
    private readonly string $connId;

    /** @var list<string> */
    private array $sent = [];

    /** @var list<array<array-key, mixed>> */
    private array $sentJson = [];

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

    public function sendJson(array $payload): void
    {
        $encoded = JsonFrame::encode($payload);

        $this->sent[] = $encoded;
        $this->sentJson[] = JsonFrame::decode($encoded);
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

    /**
     * Every payload passed to sendJson(), decoded, in order. Values are
     * post-normalization — a \DateTimeImmutable is already its RFC 3339 string —
     * which is what an assertion should compare against, since that is what the
     * client will receive.
     *
     * @return list<array<array-key, mixed>>
     */
    public function sentJson(): array
    {
        return $this->sentJson;
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
