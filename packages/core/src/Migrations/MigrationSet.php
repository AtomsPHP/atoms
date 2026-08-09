<?php

declare(strict_types=1);

namespace Atoms\Migrations;

use Atoms\Errors\AtomsError;
use Atoms\Errors\ErrorCode;

/**
 * An ordered collection of an Atom type's migrations, loaded from a directory of
 * `NNN_name.sql` and `NNN_name.php` files. Validates strictly-increasing, unique
 * integer version prefixes; content-hashes each migration for the manifest.
 *
 * @implements \IteratorAggregate<int, MigrationEntry>
 */
final class MigrationSet implements \IteratorAggregate, \Countable
{
    /**
     * @param list<MigrationEntry> $entries ordered by ascending version
     */
    private function __construct(
        private readonly array $entries,
    ) {
    }

    /**
     * Scan $dir for migration files. Missing or empty directory → empty set.
     *
     * @throws AtomsError ErrorCode E051 on a duplicate or non-numeric version prefix
     */
    public static function fromDirectory(string $dir): self
    {
        if (!is_dir($dir)) {
            return new self([]);
        }

        $files = glob(rtrim($dir, '/') . '/*.{sql,php}', GLOB_BRACE);
        if ($files === false) {
            $files = [];
        }
        sort($files, SORT_STRING);

        /** @var array<int, MigrationEntry> $byVersion */
        $byVersion = [];

        foreach ($files as $file) {
            $basename = basename($file);

            if (!preg_match('/^(\d+)_(.+)\.(sql|php)$/', $basename, $m)) {
                throw new AtomsError(
                    ErrorCode::MigrationNumberingConflict,
                    "Migration file {$basename} must be named NNN_name.sql or NNN_name.php.",
                );
            }

            $version = (int) $m[1];
            $name = $m[2];
            $extension = $m[3];

            if (isset($byVersion[$version])) {
                throw new AtomsError(
                    ErrorCode::MigrationNumberingConflict,
                    "Duplicate migration version {$version} at {$basename}.",
                );
            }

            $contents = file_get_contents($file);
            if ($contents === false) {
                throw new AtomsError(
                    ErrorCode::MigrationNumberingConflict,
                    "Could not read migration {$basename}.",
                );
            }
            $sha256 = hash('sha256', $contents);

            if ($extension === 'sql') {
                $byVersion[$version] = new MigrationEntry($version, $name, $sha256, $contents, null);
            } else {
                // Deliberately NOT loaded here: builds/validation must never
                // execute customer code. The file is require'd lazily by
                // MigrationEntry::migration() at apply time.
                $byVersion[$version] = new MigrationEntry($version, $name, $sha256, null, $file);
            }
        }

        ksort($byVersion);

        return new self(array_values($byVersion));
    }

    public function headVersion(): int
    {
        if ($this->entries === []) {
            return 0;
        }

        return $this->entries[array_key_last($this->entries)]->version;
    }

    /**
     * @return list<MigrationEntry>
     */
    public function all(): array
    {
        return $this->entries;
    }

    public function count(): int
    {
        return \count($this->entries);
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->entries);
    }
}
