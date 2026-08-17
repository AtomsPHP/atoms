<?php

declare(strict_types=1);

namespace Atoms\Websocket;

/**
 * A single WebSocket connection bound to an Atom instance. Implemented by the
 * runtime (and by the test harness); an Atom only ever sees this interface.
 */
interface Connection
{
    public function id(): string;

    public function send(string $payload): void;

    /**
     * Send a structured frame bare — no `kind`/`channel` envelope, unlike
     * `broadcast()`. The payload is normalized and encoded by
     * {@see JsonFrame::encode()}, the same encoder `broadcast()` uses.
     *
     * @param array<string, mixed> $payload
     *
     * @throws \Atoms\Serialization\SerializationException if a value is outside the type algebra
     * @throws \JsonException                              if the normalized tree cannot be encoded
     */
    public function sendJson(array $payload): void;

    public function close(int $code = 1000, string $reason = ''): void;
}
