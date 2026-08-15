<?php

declare(strict_types=1);

namespace Atoms\PHPStan\Tests\Fixtures\Dispatch;

use Atoms\AtomMethods;
use Atoms\PHPStan\Tests\Fixtures\Clean\RecordResult;

/** World B: constructing a job in the monolith is ordinary code. */
final class Methods extends AtomMethods
{
    public function build(string $id): RecordResult
    {
        return new RecordResult($id, new \DateTimeImmutable());
    }
}
