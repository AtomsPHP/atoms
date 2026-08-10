<?php

declare(strict_types=1);

namespace Atoms\Cli\Build;

use Atoms\Errors\AtomsError;
use Atoms\Errors\ErrorCode;
use Atoms\Migrations\MigrationSet;

/**
 * Stage 5: load each Atom's migration set via atoms/core's MigrationSet, so the
 * manifest can record the head version and per-file sha256 and E051 numbering
 * conflicts surface through the core validator.
 *
 * Each file also records its bundle-relative `path`. `MigrationEntry::$name` is
 * the descriptive part only (`MigrationSet` parses `NNN_name.sql` and keeps
 * `name`), so the filename cannot be reconstructed from the manifest — and a
 * bundle consumer that must apply migrations, such as the Cloudflare Worker,
 * needs the exact path. Recording it beats making every consumer re-derive the
 * `{Atom}/migrations/` layout convention.
 *
 * @phpstan-type MigrationInfo array{head: int, files: list<array{version: int, name: string, sha256: string, path: string}>}
 */
final class MigrationScanner
{
    /** @var array<string, MigrationInfo> atom FQCN => migration info */
    private array $byAtom = [];

    /** @var list<Violation> */
    private array $violations = [];

    public function scan(DiscoveryResult $discovery): self
    {
        $result = new self();
        foreach ($discovery->ofKind(ClassKind::Atom) as $atom) {
            $dir = BundleFileSet::migrationsDir($atom);
            try {
                $set = MigrationSet::fromDirectory($dir);
            } catch (AtomsError $e) {
                $file = preg_match('/(\S+\.(?:sql|php))/', $e->getMessage(), $m) === 1
                    ? $m[1]
                    : basename($dir);
                $result->violations[] = new Violation(
                    ErrorCode::MigrationNumberingConflict,
                    $atom->relativePath,
                    $atom->line,
                    ['type' => $atom->basename(), 'file' => $file],
                    $atom->fqcn,
                );
                $result->byAtom[$atom->fqcn] = ['head' => 0, 'files' => []];
                continue;
            }

            $basenames = self::basenamesByEntry($dir);

            $files = [];
            foreach ($set->all() as $entry) {
                $basename = $basenames[$entry->version . "\0" . $entry->name] ?? null;
                $files[] = [
                    'version' => $entry->version,
                    'name' => $entry->name,
                    'sha256' => $entry->sha256,
                    'path' => $basename === null
                        ? ''
                        : self::relativeMigrationsDir($atom) . '/' . $basename,
                ];
            }
            $result->byAtom[$atom->fqcn] = ['head' => $set->headVersion(), 'files' => $files];
        }

        return $result;
    }

    /**
     * @return MigrationInfo
     */
    public function forAtom(string $fqcn): array
    {
        return $this->byAtom[$fqcn] ?? ['head' => 0, 'files' => []];
    }

    /**
     * @return list<Violation>
     */
    public function violations(): array
    {
        return $this->violations;
    }

    /**
     * Map "{version}\0{name}" => filename, parsed exactly as MigrationSet parses
     * it, so the association is by identity rather than by two sorts happening
     * to agree.
     *
     * @return array<string, string>
     */
    private static function basenamesByEntry(string $dir): array
    {
        $out = [];
        foreach (BundleFileSet::migrationFiles($dir) as $absolute) {
            $basename = basename($absolute);
            if (preg_match('/^(\d+)_(.+)\.(?:sql|php)$/', $basename, $m) === 1) {
                $out[(int) $m[1] . "\0" . $m[2]] = $basename;
            }
        }

        return $out;
    }

    /**
     * The bundle-relative migrations directory: the relative-path mirror of
     * {@see BundleFileSet::migrationsDir()}, which resolves the same layout in
     * absolute terms.
     */
    private static function relativeMigrationsDir(DiscoveredClass $atom): string
    {
        $dir = \dirname($atom->relativePath);
        $prefix = $dir === '.' ? '' : $dir . '/';

        return $prefix . basename($atom->relativePath, '.php') . '/migrations';
    }
}
