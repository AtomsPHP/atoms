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
     * Send one structured frame to this connection.
     *
     * The array-in convenience `broadcast()` has always had, for a direct reply:
     * the payload is normalized and encoded by {@see JsonFrame::encode()}, the
     * same encoder `broadcast()` uses. Unlike a broadcast it is sent **bare** —
     * there is no channel to name, so there is no envelope to name it in.
     *
     * Everything {@see self::send()} does still applies: the outbound size cap,
     * and the runtime's dead-connection exception.
     *
     * @param array<string, mixed> $payload
     *
     * @throws \Atoms\Serialization\SerializationException if a value is outside the type algebra
     * @throws \JsonException                              if the normalized tree cannot be encoded
     */
    public function sendJson(array $payload): void;

    public function close(int $code = 1000, string $reason = ''): void;
}
