<?php

declare(strict_types=1);

namespace Atoms\Core\Tests\Fixtures;

use Atoms\AtomJob;

/**
 * A dispatched job whose constructor covers the named-argument cases: a
 * required parameter, a nullable one with no default, and one with a default.
 */
final class ReportJob extends AtomJob
{
    public function __construct(
        public readonly string $id,
        public readonly ?PlayerSnapshot $player,
        public readonly int $retries = 3,
    ) {
    }
}
