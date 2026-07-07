<?php

declare(strict_types=1);

namespace Atoms\Symfony\Tests\Fixtures;

use Atoms\AtomJob;

/**
 * An AtomJob whose handle() records its own invocation so tests can assert
 * the Messenger handler actually reconstructed and ran it (rather than just
 * dispatched a message).
 */
final class RecordingJob extends AtomJob
{
    /** @var list<array{playerId: string, roomSize: int}> */
    public static array $handled = [];

    public function __construct(
        public readonly string $playerId,
        public readonly int $roomSize,
    ) {
    }

    public function handle(): void
    {
        self::$handled[] = ['playerId' => $this->playerId, 'roomSize' => $this->roomSize];
    }
}
