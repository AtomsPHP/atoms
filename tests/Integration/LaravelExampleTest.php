<?php

declare(strict_types=1);

namespace Atoms\Tests\Integration;

use App\Atoms\GameRoom;
use Atoms\Laravel\Facades\Atoms;
use Atoms\Laravel\Tests\TestCase;
use Atoms\Testing\AtomHarness;

final class LaravelExampleTest extends TestCase
{
    public function testExampleAtomPersistsThroughItsRealMigration(): void
    {
        $room = AtomHarness::for(GameRoom::class, 'room-7');

        self::assertSame(1, $room->invoke('join', ['player-4']));
        self::assertSame(2, $room->invoke('join', ['player-4']));
        self::assertSame(1, $room->invoke('join', ['player-9']));
    }

    public function testExampleUsesTheLaravelFacadeContract(): void
    {
        $fake = Atoms::fake([GameRoom::class => ['join' => 2]]);

        self::assertSame(2, Atoms::get(GameRoom::class, 'room-7')->join('player-4'));
        $fake->assertInvoked(GameRoom::class, 'join');
    }
}
