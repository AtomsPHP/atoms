<?php

declare(strict_types=1);

namespace Atoms\Websocket;

/**
 * An inbound WebSocket frame delivered to {@see \Atoms\Atom::onMessage()}.
 */
interface Message
{
    public function payload(): string;

    public function isBinary(): bool;
}
