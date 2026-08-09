<?php

declare(strict_types=1);

namespace App\Atoms\Jobs;

use Atoms\AtomJob;

/**
 * World B. handle() runs in the monolith; the constructor signature is the
 * dispatch contract captured in the manifest.
 */
final class RecordGameResult extends AtomJob
{
    public function __construct(
        public readonly string $ref,
        public readonly int $seat,
    ) {
    }
}
