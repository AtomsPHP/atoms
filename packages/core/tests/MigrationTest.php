<?php

declare(strict_types=1);

namespace Atoms\Core\Tests;

use Atoms\Errors\AtomsError;
use Atoms\Errors\ErrorCode;
use Atoms\Migrations\MigrationSet;
use Atoms\Migrations\Migrator;
use Atoms\Sqlite\SqliteDatabase;
use PHPUnit\Framework\TestCase;

final class MigrationTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/atoms-mig-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->dir);
    }

    private function write(string $name, string $contents): void
    {
        file_put_contents($this->dir . '/' . $name, $contents);
    }

    public function testMissingDirectoryIsEmptySet(): void
    {
        $set = MigrationSet::fromDirectory($this->dir . '/does-not-exist');

        self::assertCount(0, $set);
        self::assertSame(0, $set->headVersion());
    }

    public function testLoadsOrdersAndHashesMigrations(): void
    {
        $this->write('002_add_index.sql', 'CREATE INDEX idx ON events (kind);');
        $this->write('001_create_events.sql', "CREATE TABLE events (id INTEGER PRIMARY KEY, kind TEXT);");

        $set = MigrationSet::fromDirectory($this->dir);

        self::assertCount(2, $set);
        self::assertSame(2, $set->headVersion());

        $all = $set->all();
        self::assertSame(1, $all[0]->version);
        self::assertSame('create_events', $all[0]->name);
        self::assertSame(2, $all[1]->version);
        self::assertSame(64, strlen($all[0]->sha256));
        self::assertSame(hash('sha256', 'CREATE INDEX idx ON events (kind);'), $all[1]->sha256);
    }

    public function testDuplicateVersionThrowsE051(): void
    {
        $this->write('001_a.sql', 'SELECT 1;');
        $this->write('001_b.sql', 'SELECT 1;');

        try {
            MigrationSet::fromDirectory($this->dir);
            self::fail('Expected AtomsError');
        } catch (AtomsError $e) {
            self::assertSame(ErrorCode::MigrationNumberingConflict, $e->errorCode);
        }
    }

    public function testNonNumericPrefixThrowsE051(): void
    {
        $this->write('create_events.sql', 'SELECT 1;');

        try {
            MigrationSet::fromDirectory($this->dir);
            self::fail('Expected AtomsError');
        } catch (AtomsError $e) {
            self::assertSame(ErrorCode::MigrationNumberingConflict, $e->errorCode);
        }
    }

    public function testMigratorAppliesInOrderAndSetsUserVersion(): void
    {
        $this->write('001_create_events.sql', 'CREATE TABLE events (id INTEGER PRIMARY KEY, kind TEXT);');
        $this->write('002_seed.sql', "INSERT INTO events (kind) VALUES ('created');");

        $db = SqliteDatabase::open(':memory:');
        $set = MigrationSet::fromDirectory($this->dir);
        $migrator = new Migrator();

        $applied = $migrator->apply($db, $set);

        self::assertSame(2, $applied);
        self::assertSame(2, (int) $db->pdo()->query('PRAGMA user_version')->fetchColumn());
        self::assertSame(1, (int) $db->pdo()->query('SELECT COUNT(*) FROM events')->fetchColumn());
    }

    public function testMigratorIsIdempotent(): void
    {
        $this->write('001_create_events.sql', 'CREATE TABLE events (id INTEGER PRIMARY KEY, kind TEXT);');

        $db = SqliteDatabase::open(':memory:');
        $migrator = new Migrator();

        self::assertSame(1, $migrator->apply($db, MigrationSet::fromDirectory($this->dir)));
        self::assertSame(0, $migrator->apply($db, MigrationSet::fromDirectory($this->dir)));
    }

    public function testPhpDataMigrationRuns(): void
    {
        $this->write('001_create_events.sql', 'CREATE TABLE events (id INTEGER PRIMARY KEY, kind TEXT);');
        $this->write(
            '002_seed.php',
            "<?php return new \\Atoms\\Core\\Tests\\Fixtures\\SeedMigration();\n",
        );

        $db = SqliteDatabase::open(':memory:');
        $applied = (new Migrator())->apply($db, MigrationSet::fromDirectory($this->dir));

        self::assertSame(2, $applied);
        self::assertSame('seeded', $db->pdo()->query('SELECT kind FROM events')->fetchColumn());
    }

    public function testFailureMidSetLeavesUserVersionAtLastSuccessAndThrowsE053(): void
    {
        $this->write('001_create_events.sql', 'CREATE TABLE events (id INTEGER PRIMARY KEY, kind TEXT);');
        $this->write('002_boom.sql', 'THIS IS NOT VALID SQL;');

        $db = SqliteDatabase::open(':memory:');
        $migrator = new Migrator();

        try {
            $migrator->apply($db, MigrationSet::fromDirectory($this->dir));
            self::fail('Expected AtomsError');
        } catch (AtomsError $e) {
            self::assertSame(ErrorCode::MigrationFailed, $e->errorCode);
            self::assertNotNull($e->getPrevious());
        }

        self::assertSame(1, (int) $db->pdo()->query('PRAGMA user_version')->fetchColumn());
        // The first migration's table still exists (committed independently).
        self::assertSame(0, (int) $db->pdo()->query('SELECT COUNT(*) FROM events')->fetchColumn());
    }
}
