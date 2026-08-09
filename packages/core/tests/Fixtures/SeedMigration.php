<?php

declare(strict_types=1);

namespace Atoms\Core\Tests\Fixtures;

use Atoms\Database;
use Atoms\Migrations\Migration;

/**
 * A PHP data migration used by the Migrator tests: seeds one row.
 */
final class SeedMigration implements Migration
{
    public function up(Database $db): void
    {
        $db->execute('INSERT INTO events (kind) VALUES (?)', ['seeded']);
    }
}
