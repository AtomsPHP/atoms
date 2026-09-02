<?php

declare(strict_types=1);

namespace App\Atoms;

use Atoms\Atom;
use Atoms\Database;
use Atoms\Websocket\Connection;
use Atoms\Websocket\Message;

/**
 * One durable game room. Each room id gets its own serialized instance and
 * SQLite database in a Cloudflare Durable Object.
 */
class GameRoom extends Atom
{
    public function join(string $playerId): int
    {
        return $this->db()->transaction(function (Database $db) use ($playerId): int {
            $db->execute(
                'INSERT INTO players (player_id, visits) VALUES (?, 1) '
                . 'ON CONFLICT(player_id) DO UPDATE SET visits = visits + 1',
                [$playerId],
            );

            $rows = $db->query('SELECT visits FROM players WHERE player_id = ?', [$playerId]);

            return (int) $rows[0]['visits'];
        });
    }

    /**
     * `client_id` arrives as a signed ticket claim, so it is the server's
     * assertion of who this is — a browser cannot put its own value there.
     */
    public function onConnect(Connection $conn, array $params): void
    {
        $playerId = $params['client_id'] ?? '';

        if ($playerId === '') {
            $conn->close(4401, 'No player identity');

            return;
        }

        // sendJson() takes the array directly, with the same encoding rules
        // broadcast() uses — no json_encode() helper to hand-roll.
        $conn->sendJson([
            'kind' => 'welcome',
            'player' => $playerId,
            'visits' => $this->join($playerId),
        ]);
    }

    public function onMessage(Connection $conn, Message $msg): void
    {
        try {
            $frame = $msg->json();
        } catch (\JsonException) {
            // One catch covers malformed JSON and a top-level non-object alike.
            $conn->sendJson(['kind' => 'error', 'reason' => 'expected a JSON object']);

            return;
        }

        if (($frame['kind'] ?? null) !== 'say') {
            $conn->sendJson(['kind' => 'error', 'reason' => 'unknown frame kind']);

            return;
        }

        $this->broadcast('room', ['kind' => 'said', 'text' => (string) ($frame['text'] ?? '')]);
    }
}
