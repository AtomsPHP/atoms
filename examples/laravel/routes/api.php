<?php

declare(strict_types=1);

use App\Atoms\GameRoom;
use Atoms\Laravel\Facades\Atoms;
use Illuminate\Support\Facades\Route;

Route::post('/rooms/{room}/players/{player}', static function (string $room, string $player): array {
    $visits = Atoms::get(GameRoom::class, $room)->join($player);

    return [
        'room' => $room,
        'player' => $player,
        'visits' => $visits,
    ];
});
