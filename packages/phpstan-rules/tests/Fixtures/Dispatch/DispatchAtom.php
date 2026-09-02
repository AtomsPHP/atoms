<?php

declare(strict_types=1);

namespace Atoms\PHPStan\Tests\Fixtures\Dispatch;

use Atoms\Atom;
use Atoms\PHPStan\Tests\Fixtures\Clean\RecordResult;

/** Atom-side. A correct dispatch, an incorrect one, and a non-job. */
final class DispatchAtom extends Atom
{
    public function legal(string $id): void
    {
        $this->dispatch(RecordResult::class, [
            'playerId' => $id,
            'recordedAt' => new \DateTimeImmutable(),
        ]);
    }

    public function illegal(string $id): void
    {
        $this->dispatch(new RecordResult($id, new \DateTimeImmutable()));
    }

    /** A non-job stays the boundary rules' business, not this one's. */
    public function notAJob(): void
    {
        $this->dispatch(new NotAJob());
    }
}
