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
 *
 * @extends Atom<\App\Atoms\GameRoom\Methods>
 */
class GameRoom extends Atom
{
    public function setScore(int $home, int $away): void
    {
        if ($home === 0 && $away === 0) {
            $changed = $this->db()->execute(
                'UPDATE scoreboard SET previous_home = home, previous_away = away,
                    clear_id = hex(randomblob(16)), home = 0, away = 0
                 WHERE home <> 0 OR away <> 0',
            );
        } else {
            $changed = $this->db()->execute(
                'UPDATE scoreboard SET home = ?, away = ?, previous_home = NULL, previous_away = NULL,
                    clear_id = NULL WHERE home <> ? OR away <> ?',
                [$home, $away, $home, $away],
            );
        }

        if ($changed === 1) {
            $this->broadcast('room', ['kind' => 'score', 'home' => $home, 'away' => $away]);
        }
    }

    /** @return array<string, mixed> */
    public function scoreboard(): array
    {
        return $this->db()->query('SELECT home, away, previous_home, previous_away, clear_id FROM scoreboard')[0];
    }

    public function restoreScore(string $clearId): bool
    {
        $rows = $this->db()->query(
            'UPDATE scoreboard SET home = previous_home, away = previous_away,
                previous_home = NULL, previous_away = NULL, clear_id = NULL
             WHERE clear_id = ? RETURNING home, away',
            [$clearId],
        );
        if ($rows === []) {
            return false;
        }

        $this->broadcast('room', ['kind' => 'score', ...$rows[0]]);

        return true;
    }

    public function appLabel(): string
    {
        return $this->app()->appLabel();
    }

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
