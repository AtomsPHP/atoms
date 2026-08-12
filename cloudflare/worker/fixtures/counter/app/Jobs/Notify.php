<?php

declare(strict_types=1);

namespace App\Jobs;

use Atoms\AtomJob;

/**
 * Notify — a fixture job for conformance testing of dispatch().
 *
 * Constructor parameters are the dispatch contract (docs/conventions.md):
 * promoted public properties only, so the runtime can read them back off the
 * object without an ORM or a deserializer.
 */
final class Notify extends AtomJob
{
    public function __construct(
        public readonly string $atomId,
        public readonly string $note,
    ) {
    }
}
