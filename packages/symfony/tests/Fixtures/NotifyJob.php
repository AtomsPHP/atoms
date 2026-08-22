<?php

declare(strict_types=1);

namespace Atoms\Symfony\Tests\Fixtures;

use Atoms\AtomJob;

/**
 * An AtomJob with an optional nullable parameter and a defaulted one, so the
 * handler's "absent argument" branches are visible in what handle() records.
 */
final class NotifyJob extends AtomJob
{
    /** @var list<array{playerId: string, note: string|null, retries: int}> */
    public static array $handled = [];

    public function __construct(
        public readonly string $playerId,
        public readonly ?string $note,
        public readonly int $retries = 3,
    ) {
    }

    public function handle(): void
    {
        self::$handled[] = ['playerId' => $this->playerId, 'note' => $this->note, 'retries' => $this->retries];
    }
}
