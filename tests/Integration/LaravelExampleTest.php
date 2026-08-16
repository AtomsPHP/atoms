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

    public function testExampleAtomSpeaksStructuredWebSocketFrames(): void
    {
        $room = AtomHarness::for(GameRoom::class, 'room-7');

        // onConnect replies with sendJson(); the harness records the decoded
        // frame, so the assertion is on the payload rather than on a string of
        // JSON the test would have to encode the same way the Atom did.
        $conn = $room->connect(['client_id' => 'player-4']);
        self::assertSame(
            [['kind' => 'welcome', 'player' => 'player-4', 'visits' => 1]],
            $conn->sentJson(),
        );

        $room->sendMessage($conn, '{"kind":"say","text":"hello"}');
        $room->assertBroadcast('room');

        // Malformed and non-object frames both reach the Atom as \JsonException.
        $room->sendMessage($conn, 'not json at all');
        self::assertSame('error', $conn->sentJson()[1]['kind']);
    }

    public function testExampleIssuesATicketAndASocketUrlThroughTheFacade(): void
    {
        config(['atoms.endpoint' => 'https://atoms-laravel-example.example.workers.dev']);
        $this->app->forgetInstance(\Atoms\Client\AtomsConfig::class);
        $this->app->forgetInstance(\Atoms\Client\AtomsClient::class);
        $this->app->forgetInstance(\Atoms\Laravel\AtomsManager::class);

        $fake = Atoms::fake();

        $ticket = Atoms::ticket(GameRoom::class, 'room-7', ['client_id' => 'player-4']);
        $fake->assertTicketIssued(
            GameRoom::class,
            'room-7',
            static fn (array $claims): bool => $claims === ['client_id' => 'player-4'],
        );

        $url = Atoms::wsUrl(GameRoom::class, 'room-7', ['channels' => ['room'], 'ticket' => (string) $ticket]);
        self::assertStringContainsString('/ws/GameRoom/room-7?channels=room&ticket=', $url);
        self::assertStringStartsWith('ws', $url);
    }
}
