<?php

declare(strict_types=1);

namespace Atoms\Migrations;

/**
 * One ordered migration within a {@see MigrationSet}: its version, name, the
 * sha256 of its file contents, and the payload (raw SQL or a PHP {@see Migration}).
 */
final class MigrationEntry
{
    public function __construct(
        public readonly int $version,
        public readonly string $name,
        public readonly string $sha256,
        public readonly ?string $sql,
        public readonly ?Migration $migration,
    ) {
    }

    public function isSql(): bool
    {
        return $this->sql !== null;
    }
}
