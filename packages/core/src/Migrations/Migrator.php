<?php

declare(strict_types=1);

namespace Atoms\Migrations;

use Atoms\Database;
use Atoms\Errors\AtomsError;
use Atoms\Errors\ErrorCode;

/**
 * Applies a {@see MigrationSet} to a {@see Database}, tracking the applied
 * version in SQLite's `user_version` pragma. Each migration runs in its own
 * transaction; a failure leaves `user_version` at the last successful migration.
 */
final class Migrator
{
    /**
     * Apply every pending migration in order, stopping at head.
     *
     * @return int the number of migrations applied
     * @throws AtomsError ErrorCode E053 if a migration fails
     */
    public function apply(Database $db, MigrationSet $set): int
    {
        $pdo = $db->pdo();
        $current = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
        $applied = 0;

        foreach ($set as $entry) {
            if ($entry->version <= $current) {
                continue;
            }

            $pdo->beginTransaction();

            try {
                if ($entry->isSql()) {
                    $pdo->exec((string) $entry->sql);
                } else {
                    $entry->migration?->up($db);
                }

                // user_version is stored in the DB header and is transactional.
                $pdo->exec('PRAGMA user_version = ' . $entry->version);
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                throw new AtomsError(
                    ErrorCode::MigrationFailed,
                    sprintf('Migration %03d_%s failed: %s', $entry->version, $entry->name, $e->getMessage()),
                    $e,
                );
            }

            $current = $entry->version;
            $applied++;
        }

        return $applied;
    }
}
