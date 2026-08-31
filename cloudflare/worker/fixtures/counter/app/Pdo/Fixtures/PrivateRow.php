<?php

declare(strict_types=1);

namespace App\Pdo\Fixtures;

/**
 * FETCH_CLASS target with private/protected declared properties plus an
 * unmatched column (design §3 F-4, measured E13): real PDO writes
 * private and protected declared properties directly, and a column with no
 * matching property becomes a dynamic public property (`$zz` here).
 *
 * `dump()` reads back through ordinary member access — legal from inside
 * the class regardless of visibility — rather than depending on the
 * differential harness's own reflection to see private/protected state.
 */
final class PrivateRow
{
    private $id;
    protected $v;

    /**
     * @return array{0: mixed, 1: mixed, 2: mixed}
     */
    public function dump(): array
    {
        return [$this->id ?? null, $this->v ?? null, $this->zz ?? null];
    }
}
