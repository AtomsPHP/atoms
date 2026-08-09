<?php

declare(strict_types=1);

namespace Atoms\Migrations;

use Atoms\Errors\AtomsError;
use Atoms\Errors\ErrorCode;

/**
 * One ordered migration within a {@see MigrationSet}: its version, name, the
 * sha256 of its file contents, and the payload (raw SQL, or the path to a PHP
 * migration file).
 *
 * PHP migration files are loaded lazily via {@see migration()} — only the
 * runtime/harness calls that, at apply time. Build-time consumers (validate,
 * manifest generation) read only the metadata and hashes, so customer code is
 * never executed during a build.
 */
final class MigrationEntry
{
    private ?Migration $loaded = null;

    public function __construct(
        public readonly int $version,
        public readonly string $name,
        public readonly string $sha256,
        public readonly ?string $sql,
        public readonly ?string $phpFile,
    ) {
    }

    public function isSql(): bool
    {
        return $this->sql !== null;
    }

    /**
     * Load the PHP migration object (apply time only — executes the file).
     *
     * @throws AtomsError ErrorCode E053 when the file does not return a Migration
     */
    public function migration(): ?Migration
    {
        if ($this->phpFile === null) {
            return null;
        }

        if ($this->loaded === null) {
            $migration = require $this->phpFile;
            if (!$migration instanceof Migration) {
                throw new AtomsError(
                    ErrorCode::MigrationFailed,
                    sprintf(
                        'Migration %s must return an instance of %s.',
                        basename($this->phpFile),
                        Migration::class,
                    ),
                );
            }
            $this->loaded = $migration;
        }

        return $this->loaded;
    }
}
