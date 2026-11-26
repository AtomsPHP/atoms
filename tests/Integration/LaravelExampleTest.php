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

    public function testSupportCanRestoreOneRoomWhileAnotherGameContinues(): void
    {
        $room = AtomHarness::for(GameRoom::class, 'final');
        $other = AtomHarness::for(GameRoom::class, 'semifinal');
        $room->invoke('setScore', [12, 9]);
        $room->invoke('setScore', [0, 0]);
        $preview = $room->invoke('scoreboard');
        self::assertSame([0, 0, 12, 9], [
            $preview['home'], $preview['away'], $preview['previous_home'], $preview['previous_away'],
        ]);
        self::assertIsString($preview['clear_id']);

        $room->invoke('setScore', [0, 0]);
        $other->invoke('setScore', [6, 5]);
        $other->invoke('setScore', [0, 0]);
        self::assertFalse($other->invoke('restoreScore', [$preview['clear_id']]));
        $other->invoke('setScore', [1, 0]);

        self::assertTrue($room->invoke('restoreScore', [$preview['clear_id']]));
        self::assertFalse($room->invoke('restoreScore', [$preview['clear_id']]));
        $score = $room->invoke('scoreboard');
        self::assertSame([12, 9, null], [$score['home'], $score['away'], $score['clear_id']]);
        self::assertSame([
            ['channel' => 'room', 'payload' => ['kind' => 'score', 'home' => 12, 'away' => 9]],
            ['channel' => 'room', 'payload' => ['kind' => 'score', 'home' => 0, 'away' => 0]],
            ['channel' => 'room', 'payload' => ['kind' => 'score', 'home' => 12, 'away' => 9]],
        ], $room->broadcasts());
        $otherScore = $other->invoke('scoreboard');
        self::assertSame([1, 0], [$otherScore['home'], $otherScore['away']]);
    }

    public function testAStalePreviewCannotReplaceNewPointsOrUndoALaterClear(): void
    {
        $room = AtomHarness::for(GameRoom::class, 'final');
        $room->invoke('setScore', [4, 2]);
        $room->invoke('setScore', [0, 0]);
        $old = $room->invoke('scoreboard')['clear_id'];

        $room->invoke('setScore', [1, 2]);
        self::assertFalse($room->invoke('restoreScore', [$old]));
        $room->invoke('setScore', [0, 0]);
        self::assertFalse($room->invoke('restoreScore', [$old]));
        $preview = $room->invoke('scoreboard');
        self::assertSame([1, 2], [$preview['previous_home'], $preview['previous_away']]);
    }

    public function testSupportCommandConfirmsThePreviewBeforeRestoring(): void
    {
        require __DIR__ . '/../../examples/laravel/routes/console.php';
        $fake = Atoms::fake([GameRoom::class => [
            'scoreboard' => ['home' => 0, 'away' => 0, 'previous_home' => 5, 'previous_away' => 3, 'clear_id' => 'abc'],
            'restoreScore' => true,
        ]]);

        $this->artisan('scoreboard:recover final')
            ->expectsOutput('Room final. Current score: 0 to 0.')
            ->expectsOutput('Score before the clear: 5 to 3.')
            ->expectsConfirmation('Restore this score?', 'no')
            ->assertSuccessful();
        $fake->assertNotInvoked(GameRoom::class, 'restoreScore');

        $this->artisan('scoreboard:recover final')
            ->expectsConfirmation('Restore this score?', 'yes')
            ->expectsOutput('Score restored.')
            ->assertSuccessful();
        self::assertSame([
            'type' => 'GameRoom', 'id' => 'final', 'method' => 'restoreScore', 'args' => ['abc'],
        ], $fake->invocations()[2]);
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
