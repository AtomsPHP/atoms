<?php

declare(strict_types=1);

use App\Atoms\GameRoom;
use Atoms\Laravel\Facades\Atoms;
use Illuminate\Support\Facades\Artisan;

Artisan::command('scoreboard:recover {room}', function (string $room): int {
    $game = Atoms::get(GameRoom::class, $room);
    $score = $game->scoreboard();
    $this->line("Room {$room}. Current score: {$score['home']} to {$score['away']}.");

    if ($score['clear_id'] === null) {
        $this->line('This room has no cleared score to restore.');

        return 0;
    }

    $this->line("Score before the clear: {$score['previous_home']} to {$score['previous_away']}.");
    if (!$this->confirm('Restore this score?')) {
        return 0;
    }

    if (!$game->restoreScore($score['clear_id'])) {
        $this->error('The scoreboard changed. Run the command again to see the current score.');

        return 1;
    }

    $this->info('Score restored.');

    return 0;
})->purpose('Preview and restore the last cleared score in one room');
