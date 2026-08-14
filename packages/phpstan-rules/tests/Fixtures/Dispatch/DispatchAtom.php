<?php

declare(strict_types=1);

namespace Atoms\PHPStan\Tests\Fixtures\Dispatch;

use Atoms\Atom;
use Atoms\PHPStan\Tests\Fixtures\Clean\RecordResult;

/**
 * World A. Both dispatch forms, so the rule has to tell them apart rather than
 * flagging every mention of a job class.
 */
final class DispatchAtom extends Atom
{
    public function legal(string $id): void
    {
        // By name: a compile-time constant, so the job never has to ship.
        $this->dispatchJob(RecordResult::class, [
            'playerId' => $id,
            'recordedAt' => new \DateTimeImmutable(),
        ]);
    }

    public function illegal(string $id): void
    {
        $this->dispatch(new RecordResult($id, new \DateTimeImmutable()));
    }

    public function notAJob(): void
    {
        // Constructing a non-job under the Atoms path stays the boundary
        // rules' business, not this one's.
        $this->dispatch(new NotAJob());
    }
}
