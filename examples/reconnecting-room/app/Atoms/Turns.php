<?php

declare(strict_types=1);

namespace App\Atoms;

use Atoms\Atom;
use Atoms\Websocket\Connection;
use Atoms\Websocket\Message;

final class Turns extends Atom
{
    public function start(string $firstPlayer, string $secondPlayer): void
    {
        $this->db()->execute('INSERT INTO players (seat, player) VALUES (1, ?), (2, ?)', [$firstPlayer, $secondPlayer]);
    }

    public function onConnect(Connection $conn, array $params): void
    {
        $player = $params['client_id'] ?? '';
        $rows = $this->db()->query('SELECT seat FROM players WHERE player = ?', [$player]);
        if ($rows === []) {
            $conn->close(4403, 'No seat for this player');
            return;
        }

        $this->db()->execute('UPDATE players SET socket = ? WHERE player = ?', [$conn->id(), $player]);
        $conn->sendJson(['kind' => 'welcome', 'seat' => $rows[0]['seat'], 'game' => $this->game()]);
    }

    public function onMessage(Connection $conn, Message $msg): void
    {
        try {
            $move = $msg->json();
        } catch (\JsonException) {
            return;
        }

        if (!is_string($move['name'] ?? null) || !is_int($move['revision'] ?? null)) {
            return;
        }

        $updated = $this->db()->execute(
            'UPDATE game SET last_move = ?, revision = revision + 1 '
            . 'WHERE revision = ? AND revision % 2 + 1 = (SELECT seat FROM players WHERE socket = ?)',
            [$move['name'], $move['revision'], $conn->id()],
        );

        if ($updated === 1) {
            $this->broadcast('game', $this->game());
        }
    }

    /** @return array<string, mixed> */
    private function game(): array
    {
        return $this->db()->query('SELECT revision, last_move FROM game')[0];
    }
}
