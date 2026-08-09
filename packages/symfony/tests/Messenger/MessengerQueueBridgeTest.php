<?php

declare(strict_types=1);

namespace Atoms\Symfony\Tests\Messenger;

use Atoms\Symfony\Messenger\AtomJobHandler;
use Atoms\Symfony\Messenger\AtomJobMessage;
use Atoms\Symfony\Messenger\MessengerQueueBridge;
use Atoms\Symfony\Tests\Fixtures\RecordingJob;
use Atoms\Symfony\Tests\Support\RecordingMessageBus;
use PHPUnit\Framework\TestCase;

final class MessengerQueueBridgeTest extends TestCase
{
    protected function setUp(): void
    {
        RecordingJob::$handled = [];
    }

    public function testEnqueueDispatchesAnAtomJobMessageWithNormalizedArgs(): void
    {
        $bus = new RecordingMessageBus();
        $bridge = new MessengerQueueBridge($bus);

        $bridge->enqueue(new RecordingJob(playerId: 'p-9', roomSize: 4));

        self::assertCount(1, $bus->dispatched);
        $message = $bus->dispatched[0];
        self::assertInstanceOf(AtomJobMessage::class, $message);
        self::assertSame(RecordingJob::class, $message->jobClass);
        self::assertSame(['playerId' => 'p-9', 'roomSize' => 4], $message->args);
    }

    public function testAtomJobHandlerReconstructsAndRunsTheJob(): void
    {
        $handler = new AtomJobHandler();

        $handler(new AtomJobMessage(RecordingJob::class, ['playerId' => 'p-1', 'roomSize' => 2]));

        self::assertSame([['playerId' => 'p-1', 'roomSize' => 2]], RecordingJob::$handled);
    }

    public function testAtomJobHandlerRejectsAClassThatIsNotAnAtomJob(): void
    {
        $this->expectException(\RuntimeException::class);

        (new AtomJobHandler())(new AtomJobMessage(self::class, []));
    }

    public function testEndToEndThroughARealBus(): void
    {
        $bus = new RecordingMessageBus();
        $bridge = new MessengerQueueBridge($bus);
        $handler = new AtomJobHandler();

        $bridge->enqueue(new RecordingJob(playerId: 'p-42', roomSize: 8));

        self::assertCount(1, $bus->dispatched);
        $handler($bus->dispatched[0]);

        self::assertSame([['playerId' => 'p-42', 'roomSize' => 8]], RecordingJob::$handled);
    }
}
