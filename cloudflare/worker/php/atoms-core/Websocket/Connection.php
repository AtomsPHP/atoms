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

    public function close(int $code = 1000, string $reason = ''): void;
}
