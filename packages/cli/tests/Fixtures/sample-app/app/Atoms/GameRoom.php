<?php

declare(strict_types=1);

namespace App\Atoms;

use App\Atoms\GameRoom\Support\ScoreBoard;
use App\Atoms\Jobs\RecordGameResult;
use App\Atoms\Shared\PlayerSnapshot;
use Atoms\Atom;
use Atoms\Websocket\Connection;

/**
 * Atom-side. A realistic Atom: SQLite writes via db(), a reverse RPC into its
 * Methods class, a dispatched AtomJob, a support class, and a WebSocket
 * handler override.
 */
final class GameRoom extends Atom
{
    public function join(?int $seat): PlayerSnapshot
    {
        $ref = (new ScoreBoard())->entryRef();
        $this->db()->execute('INSERT INTO game_room_events (payload) VALUES (?)', [$ref]);

        $player = $this->app()->getPlayer($ref);
        $this->dispatch(RecordGameResult::class, ['ref' => $ref, 'seat' => $seat ?? 0]);

        return $player;
    }

    public function onConnect(Connection $conn, array $params): void
    {
        $conn->send('welcome');
    }
}
