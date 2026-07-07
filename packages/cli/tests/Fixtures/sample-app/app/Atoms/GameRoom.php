<?php

declare(strict_types=1);

namespace App\Atoms;

use App\Atoms\Jobs\RecordGameResult;
use App\Atoms\Shared\PlayerSnapshot;
use Atoms\Atom;
use Atoms\Websocket\Connection;
use Ramsey\Uuid\Uuid;

/**
 * World A. A realistic Atom: SQLite writes via db(), a reverse RPC into its
 * Methods class, a dispatched AtomJob, an approved vendor dependency, and a
 * WebSocket handler override.
 */
final class GameRoom extends Atom
{
    public function join(?int $seat): PlayerSnapshot
    {
        $ref = Uuid::uuid4()->toString();
        $this->db()->execute('INSERT INTO game_room_events (payload) VALUES (?)', [$ref]);

        $player = $this->app()->getPlayer($ref);
        $this->dispatch(new RecordGameResult($ref, $seat ?? 0));

        return $player;
    }

    public function onConnect(Connection $conn, array $params): void
    {
        $conn->send('welcome');
    }
}
