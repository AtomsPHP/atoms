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

    /**
     * Exactly one of $sql and $phpFile must be non-null. The two are one
     * payload in two shapes, not two optional fields, and {@see isSql()} reads
     * $sql alone to tell the shapes apart — so a $phpFile entry must leave $sql
     * null, or it will be applied as SQL and never loaded.
     *
     * The constructor does not enforce this. It is public ABI, and every
     * in-repo entry comes from {@see MigrationSet::fromDirectory()}, which
     * upholds the invariant by construction; a loader outside this package that
     * builds entries directly owns it instead.
     *
     * Neither set is the case that costs something, because nothing downstream
     * reports it: {@see migration()} returns null when $phpFile is, and
     * {@see Migrator::apply()} bumps `PRAGMA user_version` and commits whether
     * or not a payload ran. Such an entry marks its version applied having
     * done nothing, and the next run reads it as already applied.
     *
     * @param string $sha256 of the migration file's contents
     * @param string|null $sql the migration's SQL, for a `NNN_name.sql` entry
     * @param string|null $phpFile path to a `NNN_name.php` file returning a
     *                             {@see Migration}, for a PHP entry
     */
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
