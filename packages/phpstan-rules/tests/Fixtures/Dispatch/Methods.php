<?php

declare(strict_types=1);

namespace Atoms\PHPStan\Tests\Fixtures\Dispatch;

use Atoms\AtomMethods;
use Atoms\PHPStan\Tests\Fixtures\Clean\RecordResult;

/**
 * World B. Runs in the monolith, where every job class is autoloadable —
 * constructing one here is ordinary code, not a boundary violation.
 */
final class Methods extends AtomMethods
{
    public function build(string $id): RecordResult
    {
        return new RecordResult($id, new \DateTimeImmutable());
    }
}
