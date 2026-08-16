<?php

declare(strict_types=1);

namespace Atoms\Websocket;

/**
 * An inbound WebSocket frame delivered to {@see \Atoms\Atom::onMessage()}.
 */
interface Message
{
    public function payload(): string;

    /**
     * Decode this frame as a structured payload.
     *
     * The inbound half of {@see Connection::sendJson()}, so an `onMessage()`
     * handler does not repeat the decode-and-validate boilerplate. Only a JSON
     * object is a frame: malformed input and a top-level list, scalar or `null`
     * both throw `\JsonException`, so one catch covers every unusable frame.
     *
     * This decodes {@see self::payload()}, which is byte-safe, so it also works
     * on a binary frame whose contents happen to be JSON. Check
     * {@see self::isBinary()} first if that distinction matters to your protocol.
     *
     * @return array<array-key, mixed>
     *
     * @throws \JsonException if the payload is not a JSON object
     */
    public function json(): array;

    public function isBinary(): bool;
}
