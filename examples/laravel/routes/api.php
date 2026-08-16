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

/*
 * A browser cannot put an Authorization header on `new WebSocket(url)`, so it
 * presents a short-lived ticket instead. Your app already holds the shared
 * secret, so issuing one is local computation — there is no round trip here,
 * and nothing to be unavailable.
 *
 * The seat identity is asserted here, server-side, and travels as a signed
 * claim the Worker merges *over* the browser's query params. That is what makes
 * it unforgeable: asking for a ticket only ever gets you your own identity.
 */
Route::post('/rooms/{room}/socket', static function (string $room, string $player = 'anonymous'): array {
    $ticket = Atoms::ticket(GameRoom::class, $room, ['client_id' => $player]);

    return [
        'url' => Atoms::wsUrl(GameRoom::class, $room, [
            'channels' => ['room'],
            'ticket' => (string) $ticket,
        ]),
    ];
});
