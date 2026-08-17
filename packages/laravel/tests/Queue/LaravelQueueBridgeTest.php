<?php

declare(strict_types=1);

namespace Atoms\Laravel\Tests\Queue;

use Atoms\Errors\AtomsError;
use Atoms\Errors\ErrorCode;
use Atoms\Laravel\Queue\AtomJobEnvelope;
use Atoms\Laravel\Tests\Fixtures\GameRoom;
use Atoms\Laravel\Tests\Fixtures\RecordScoreJob;
use Atoms\Serialization\SerializationException;
use Atoms\Laravel\Tests\TestCase;
use Illuminate\Support\Facades\Queue;

final class LaravelQueueBridgeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        RecordScoreJob::$handled = [];
    }

    public function testJobCallbackPushesAnEnvelopeOntoTheQueue(): void
    {
        Queue::fake();

        [$server, $body] = $this->signedCallback('job', [
            'job' => RecordScoreJob::class,
            'args' => ['playerId' => 'p-1', 'score' => 42],
        ]);

        $response = $this->call('POST', '/atoms/callback', [], [], [], $server, $body);

        $response->assertStatus(200);
        $response->assertJson(['queued' => true]);

        Queue::assertPushed(
            AtomJobEnvelope::class,
            static fn (AtomJobEnvelope $envelope): bool => $envelope->jobClass === RecordScoreJob::class
                && $envelope->args === ['playerId' => 'p-1', 'score' => 42],
        );
    }

    public function testEnvelopeHandleReconstructsAndRunsTheAtomJob(): void
    {
        $envelope = new AtomJobEnvelope(RecordScoreJob::class, ['playerId' => 'p-2', 'score' => 10]);

        $envelope->handle();

        self::assertSame([['playerId' => 'p-2', 'score' => 10]], RecordScoreJob::$handled);
    }

    public function testEnvelopeHandleThrowsCataloguedE024WhenARequiredArgIsMissing(): void
    {
        $envelope = new AtomJobEnvelope(RecordScoreJob::class, ['playerId' => 'p-3']);

        try {
            $envelope->handle();
            self::fail('Expected SerializationException');
        } catch (SerializationException $e) {
            self::assertSame(ErrorCode::BoundaryTypeMismatch, $e->errorCode);
            self::assertStringContainsString('score', $e->getMessage());
        }
    }

    public function testEnvelopeHandleThrowsCataloguedE024WhenAnArgHasTheWrongType(): void
    {
        $envelope = new AtomJobEnvelope(RecordScoreJob::class, ['playerId' => 'p-4', 'score' => 'ten']);

        try {
            $envelope->handle();
            self::fail('Expected SerializationException');
        } catch (SerializationException $e) {
            self::assertSame(ErrorCode::BoundaryTypeMismatch, $e->errorCode);
        }
    }

    public function testEnvelopeHandleRefusesAClassThatIsNotAnAtomJob(): void
    {
        // A class-string that is not an AtomJob: the queue payload is data, so
        // the guard has to hold at reconstruction time, not only at the type level.
        $envelope = new AtomJobEnvelope(GameRoom::class, []);

        try {
            $envelope->handle();
            self::fail('Expected AtomsError');
        } catch (AtomsError $e) {
            self::assertSame(ErrorCode::NotAnAtomJob, $e->errorCode);
        }

        self::assertSame([], RecordScoreJob::$handled);
    }
}
