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
     * The inbound half of {@see Connection::sendJson()}. Decodes the raw
     * {@see self::payload()} — see {@see JsonFrame::decode()} for what the payload
     * must decode to and why one catch covers every failure. Works whether this
     * message is binary or text, as long as its contents are JSON; check
     * {@see self::isBinary()} first if that distinction matters to your protocol.
     *
     * @return array<array-key, mixed>
     *
     * @throws \JsonException if the payload does not decode to a JSON object
     */
    public function json(): array;

    public function isBinary(): bool;
}
