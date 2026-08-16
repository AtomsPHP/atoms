<?php

declare(strict_types=1);

namespace Atoms\Laravel\Tests\Queue;

use Atoms\Laravel\Queue\AtomJobEnvelope;
use Atoms\Laravel\Tests\Fixtures\RecordScoreJob;
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

    public function testEnvelopeHandleThrowsWhenARequiredArgIsMissing(): void
    {
        $envelope = new AtomJobEnvelope(RecordScoreJob::class, ['playerId' => 'p-3']);

        $this->expectException(\RuntimeException::class);
        $envelope->handle();
    }
}
