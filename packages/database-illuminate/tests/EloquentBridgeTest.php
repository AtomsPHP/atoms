<?php

declare(strict_types=1);

namespace Atoms\DatabaseIlluminate\Tests;

use Atoms\DatabaseIlluminate\AtomConnection;
use Atoms\DatabaseIlluminate\EloquentBridge;
use Atoms\DatabaseIlluminate\Tests\Support\Note;
use Atoms\Sqlite\SqliteDatabase;
use PHPUnit\Framework\TestCase;

final class EloquentBridgeTest extends TestCase
{
    protected function tearDown(): void
    {
        EloquentBridge::reset();
    }

    public function testBootReturnsAConnectionOverTheDatabasesOwnPdo(): void
    {
        $db = SqliteDatabase::open(':memory:');
        $conn = EloquentBridge::boot($db);

        self::assertInstanceOf(AtomConnection::class, $conn);
        self::assertSame($db->pdo(), $conn->getPdo());
        self::assertSame(EloquentBridge::CONNECTION_NAME, $conn->getName());
    }

    public function testBootIsIdempotentPerDatabaseInstance(): void
    {
        $db = SqliteDatabase::open(':memory:');

        self::assertSame(EloquentBridge::boot($db), EloquentBridge::boot($db));
    }

    public function testExtraConfigReachesTheConnection(): void
    {
        $conn = EloquentBridge::boot(SqliteDatabase::open(':memory:'), ['server_version' => '3.50.0']);

        self::assertSame('3.50.0', $conn->getServerVersion());
    }

    public function testEloquentModelsRunAgainstTheAtomDatabase(): void
    {
        $db = SqliteDatabase::open(':memory:');
        $db->execute('CREATE TABLE notes (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, body TEXT)');

        EloquentBridge::boot($db);

        $note = Note::create(['title' => 'first', 'body' => 'hello']);
        $note->body = 'updated';
        $note->save();

        self::assertSame('updated', Note::query()->findOrFail($note->id)->body);

        // One database, two views: the plain Atoms surface sees Eloquent's writes.
        self::assertSame(
            [['title' => 'first']],
            $db->query('SELECT title FROM notes'),
        );
    }

    public function testRebootingACachedDatabaseRepointsTheGlobalResolver(): void
    {
        $a = SqliteDatabase::open(':memory:');
        $a->execute('CREATE TABLE notes (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, body TEXT)');
        $b = SqliteDatabase::open(':memory:');
        $b->execute('CREATE TABLE notes (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, body TEXT)');

        EloquentBridge::boot($a);
        Note::create(['title' => 'in-a']);
        EloquentBridge::boot($b);
        Note::create(['title' => 'in-b']);

        // This boot returns A's cached connection and must also repoint
        // the process-global resolver back at A.
        $again = EloquentBridge::boot($a);

        self::assertSame($a->pdo(), $again->getPdo());
        self::assertSame(['in-a'], Note::query()->pluck('title')->all());
    }

    public function testResetForgetsTheBootedConnection(): void
    {
        $db = SqliteDatabase::open(':memory:');
        $first = EloquentBridge::boot($db);

        EloquentBridge::reset();

        self::assertNotSame($first, EloquentBridge::boot($db));
    }
}
