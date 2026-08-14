<?php

declare(strict_types=1);

namespace App\Atoms;

use Atoms\Atom;
use Atoms\Database;

/**
 * One durable game room. Each room id gets its own serialized instance and
 * SQLite database in a Cloudflare Durable Object.
 */
final class GameRoom extends Atom
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
}
