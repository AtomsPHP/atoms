<?php

declare(strict_types=1);

namespace App\Atoms\Jobs;

use Atoms\AtomJob;

final class BadJob extends AtomJob
{
    public function __construct(
        public readonly string $ref,
        public readonly int $count,
    ) {
    }
}
