<?php

declare(strict_types=1);

namespace App\Atoms;

use Atoms\Atom;
use Atoms\Websocket\Connection;

/**
 * An intermediate base an application shares between Atom types. Discovery
 * parses files rather than loading classes, so a subclass of this one cannot
 * be shown to have no handlers — see Subroom.
 */
abstract class Roomish extends Atom
{
    public function onConnect(Connection $conn, array $params): void
    {
        $conn->send('welcome');
    }
}
