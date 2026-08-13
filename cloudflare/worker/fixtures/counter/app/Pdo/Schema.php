<?php

declare(strict_types=1);

namespace App\Pdo;

/**
 * Schema and seed parity for the differential harness (M1 design §2.2).
 * There is exactly one copy of the DDL — the migration file — so drift
 * between the DO side (via the real `Atoms\Migrations\Migrator`, applied at
 * Probe's activation) and the comparator (via {@see applySchema}) is
 * impossible: there is nothing to drift FROM.
 */
final class Schema
{
    private const MIGRATION_PATH = '/app/migrations/probe/001_init.sql';

    private function __construct()
    {
    }

    /**
     * Read the migration file from the guest FS, split it on ';', exec each
     * statement. Used only by the comparator — the DO side already has this
     * schema via Probe's manifest-declared migration.
     *
     * The naive splitter (design §2.2) cannot see across a full-line SQL
     * comment, and this file's OWN header comment contains a literal ';'
     * character (documenting the splitter itself) that would otherwise be
     * cut mid-line. Comment lines are stripped first so the split only ever
     * lands on a real statement terminator — the file still carries exactly
     * one copy of the DDL; this is parsing the comment convention around it,
     * not a second source of truth for the schema.
     */
    public static function applySchema(\PDO $pdo): void
    {
        $sql = file_get_contents(self::MIGRATION_PATH);
        if ($sql === false) {
            throw new \RuntimeException('could not read ' . self::MIGRATION_PATH);
        }

        $withoutComments = preg_replace('/^[ \t]*--.*$/m', '', $sql);
        if ($withoutComments === null) {
            throw new \RuntimeException('comment-stripping regex failed on ' . self::MIGRATION_PATH);
        }

        foreach (explode(';', $withoutComments) as $statement) {
            $statement = trim($statement);
            if ($statement === '') {
                continue;
            }
            $pdo->exec($statement);
        }
    }

    /**
     * Fixed, deterministic seed rows (design §2.2). Executed through the
     * passed-in \PDO, so seeding itself is exercised on both sides.
     *
     * probe_rows: 3 rows, k ordered a<b<c (== insertion/id order), covering
     * every scalar type the matrix needs: an integral REAL (b.r = 2.0, for
     * `value.float_integral`), an empty string (c.s), and a NULL (a.n).
     */
    public static function applySeed(\PDO $pdo): void
    {
        $pdo->exec(
            "INSERT INTO probe_rows (k, i, r, s, n) VALUES "
            . "('a', 1, 1.5, 'hello', NULL), "
            . "('b', 2, 2.0, 'world', 'nb'), "
            . "('c', 3, 3.25, '', 'nc')"
        );
    }

    /**
     * Clear the DO-side tables and their autoincrement counters, then
     * reseed — so every `Probe::differential($group)` call starts from
     * identical, reproducible state regardless of what a previous group's
     * cases wrote (design §2.2). The comparator needs no equivalent: it is
     * a brand new `sqlite::memory:` connection every call.
     *
     * `sqlite_sequence` does not exist until the first AUTOINCREMENT insert
     * has ever happened on this connection, so the very first reset() of a
     * fresh Probe residency would fail on that DELETE — harmless and
     * expected, and not part of the surface under test, so it is swallowed
     * rather than guarded with a schema probe.
     */
    public static function reset(\PDO $pdo): void
    {
        $pdo->exec('DELETE FROM probe_rows');
        $pdo->exec('DELETE FROM probe_wide');
        $pdo->exec('DELETE FROM probe_bulk');

        try {
            $pdo->exec("DELETE FROM sqlite_sequence WHERE name IN ('probe_rows', 'probe_wide', 'probe_bulk')");
        } catch (\Throwable $e) {
            // sqlite_sequence doesn't exist yet — nothing has autoincremented
            // on this connection before. Nothing to clear.
        }

        self::applySeed($pdo);
    }
}
