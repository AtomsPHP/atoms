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
 * @phpstan-type MigrationInfo array{head: int, files: list<array{version: int, name: string, sha256: string}>}
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

            $files = [];
            foreach ($set->all() as $entry) {
                $files[] = [
                    'version' => $entry->version,
                    'name' => $entry->name,
                    'sha256' => $entry->sha256,
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
}
