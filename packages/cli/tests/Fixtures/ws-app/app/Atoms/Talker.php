<?php

declare(strict_types=1);

namespace App\Atoms;

use Atoms\Atom;
use Atoms\Websocket\Connection;
use Atoms\Websocket\Message;

/**
 * Declares a handler itself, spelled in a different case from the base class's
 * declaration. PHP method names are case-insensitive, so this really does
 * override Atom::onMessage() — a name comparison that is not case-insensitive
 * would emit "websocket": false here and 501 a type whose handler works.
 */
final class Talker extends Atom
{
    public function onmessage(Connection $conn, Message $msg): void
    {
        $conn->send($msg->payload());
    }
}
