<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Atoms\GameRoom;
use Atoms\Laravel\Facades\Atoms;
use Tests\TestCase;

final class GameRoomTest extends TestCase
{
    public function testTheRouteCallsTheAtomThroughTheLaravelFacade(): void
    {
        $fake = Atoms::fake([
            GameRoom::class => ['join' => 2],
        ]);

        $this->postJson('/rooms/room-7/players/player-4')
            ->assertOk()
            ->assertExactJson([
                'room' => 'room-7',
                'player' => 'player-4',
                'visits' => 2,
            ]);

        $fake->assertInvoked(
            GameRoom::class,
            'join',
            static fn (string $player): bool => $player === 'player-4',
        );
    }
}
