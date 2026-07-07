<?php

declare(strict_types=1);

namespace Atoms\Core\Tests;

use Atoms\Database;
use Atoms\Sqlite\SqliteDatabase;
use PHPUnit\Framework\TestCase;

final class SqliteDatabaseTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/atoms-db-' . bin2hex(random_bytes(6)) . '/sub/atom.sqlite';
    }

    protected function tearDown(): void
    {
        $dir = dirname($this->path);
        foreach (glob($dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
        @rmdir(dirname($dir));
    }

    public function testOpenCreatesParentDirsAndAppliesWalOnFileDb(): void
    {
        $db = SqliteDatabase::open($this->path);

        self::assertFileExists($this->path);
        self::assertSame('wal', strtolower((string) $db->pdo()->query('PRAGMA journal_mode')->fetchColumn()));
        self::assertSame(1, (int) $db->pdo()->query('PRAGMA foreign_keys')->fetchColumn());
        self::assertSame(5000, (int) $db->pdo()->query('PRAGMA busy_timeout')->fetchColumn());
    }

    public function testMemoryDatabaseSkipsWal(): void
    {
        $db = SqliteDatabase::open(':memory:');

        // :memory: cannot use WAL; it stays in the default 'memory' journal mode.
        self::assertNotSame('wal', strtolower((string) $db->pdo()->query('PRAGMA journal_mode')->fetchColumn()));
    }

    public function testQueryExecuteRoundTrip(): void
    {
        $db = SqliteDatabase::open(':memory:');
        $db->execute('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)');

        $affected = $db->execute('INSERT INTO t (name) VALUES (?)', ['alice']);
        self::assertSame(1, $affected);

        $rows = $db->query('SELECT id, name FROM t');
        self::assertSame([['id' => 1, 'name' => 'alice']], $rows);
    }

    public function testNamedBindings(): void
    {
        $db = SqliteDatabase::open(':memory:');
        $db->execute('CREATE TABLE t (name TEXT)');
        $db->execute('INSERT INTO t (name) VALUES (:name)', [':name' => 'bob']);

        self::assertSame('bob', $db->query('SELECT name FROM t')[0]['name']);
    }

    public function testTransactionCommits(): void
    {
        $db = SqliteDatabase::open(':memory:');
        $db->execute('CREATE TABLE t (n INTEGER)');

        $result = $db->transaction(function (Database $tx): string {
            $tx->execute('INSERT INTO t (n) VALUES (1)');
            $tx->execute('INSERT INTO t (n) VALUES (2)');

            return 'ok';
        });

        self::assertSame('ok', $result);
        self::assertSame(2, (int) $db->pdo()->query('SELECT COUNT(*) FROM t')->fetchColumn());
    }

    public function testTransactionRollsBackOnException(): void
    {
        $db = SqliteDatabase::open(':memory:');
        $db->execute('CREATE TABLE t (n INTEGER)');

        try {
            $db->transaction(function (Database $tx): void {
                $tx->execute('INSERT INTO t (n) VALUES (1)');
                throw new \RuntimeException('boom');
            });
            self::fail('Expected exception to propagate');
        } catch (\RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        self::assertSame(0, (int) $db->pdo()->query('SELECT COUNT(*) FROM t')->fetchColumn());
        self::assertFalse($db->pdo()->inTransaction());
    }

    public function testNestedTransactionReusesOuter(): void
    {
        $db = SqliteDatabase::open(':memory:');
        $db->execute('CREATE TABLE t (n INTEGER)');

        $db->transaction(function (Database $tx): void {
            $tx->execute('INSERT INTO t (n) VALUES (1)');
            $tx->transaction(function (Database $inner): void {
                $inner->execute('INSERT INTO t (n) VALUES (2)');
            });
        });

        self::assertSame(2, (int) $db->pdo()->query('SELECT COUNT(*) FROM t')->fetchColumn());
    }

    public function testConstructorAcceptsExistingPdo(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $db = new SqliteDatabase($pdo);

        self::assertSame($pdo, $db->pdo());
    }
}
