<?php

declare(strict_types=1);

namespace Atoms\Laravel\Tests\Callback;

use Atoms\Client\Callback\NonceStoreConfigurationException;
use Atoms\Client\Callback\NonceStoreOptions;
use Atoms\Client\Callback\NonceStoreUnavailableException;
use Atoms\Laravel\Callback\DatabaseNonceStore;
use Atoms\Laravel\Tests\TestCase;
use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;

final class DatabaseNoncePortabilityTest extends TestCase
{
    public static function drivers(): iterable
    {
        yield 'MySQL' => ['mysql'];
        yield 'PostgreSQL' => ['pgsql'];
    }

    #[DataProvider('drivers')]
    public function testFrameworkGrammarCompilesTheMigrationAndClaim(string $driver): void
    {
        $queries = [];
        $connection = $this->connection($driver, $queries);
        config([
            'database.connections.nonce_dialect' => ['driver' => $driver, 'database' => 'nonce_test'],
            'atoms.callback_nonce.database_connection' => 'nonce_dialect',
        ]);
        $this->app['db']->extend('nonce_dialect', static fn () => $connection);
        $migration = require dirname(__DIR__, 2) . '/database/migrations/2026_10_29_000000_create_atoms_callback_nonces_table.php';
        $schema = $connection->pretend(static fn () => $migration->up());
        $sql = implode("\n", array_column($schema, 'query'));
        $quote = $driver === 'mysql' ? '`' : '"';
        self::assertStringContainsString('create table ' . $quote . DatabaseNonceStore::TABLE . $quote, $sql);
        self::assertStringContainsString($quote . 'nonce_hash' . $quote . ' varchar(64) not null', $sql);
        self::assertStringContainsString($quote . 'expires_at' . $quote . ' bigint not null', $sql);
        self::assertStringContainsString('atoms_callback_nonces_nonce_hash_unique', $sql);
        self::assertStringContainsString('atoms_callback_nonces_expiry', $sql);
        self::assertStringContainsString('unique', $sql);

        $store = new DatabaseNonceStore($connection, $this->nonceOptions());
        self::assertFalse($store->seen('nonce'));
        $insert = array_values(array_filter($queries, static fn (string $query): bool => str_starts_with($query, 'insert')));
        self::assertCount(1, $insert);
        self::assertSame(
            'insert into ' . $quote . DatabaseNonceStore::TABLE . $quote . ' (' . $quote . 'nonce_hash' . $quote
                . ', ' . $quote . 'expires_at' . $quote . ') values (?, ?)',
            $insert[0],
        );
    }

    public static function databaseErrors(): iterable
    {
        yield 'MySQL duplicate' => ['mysql', 'SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry', '23000', true];
        yield 'PostgreSQL duplicate' => ['pgsql', 'duplicate key value violates unique constraint', '23505', true];
        yield 'MySQL other integrity error' => ['mysql', 'SQLSTATE[23000]: Integrity constraint violation: 3819 Check constraint failed', '23000', false];
        yield 'PostgreSQL other integrity error' => ['pgsql', 'check constraint failed', '23514', false];
    }

    #[DataProvider('databaseErrors')]
    public function testOnlyNormalizedUniqueViolationsMeanReplay(string $driver, string $message, string $state, bool $duplicate): void
    {
        $error = new class($message, $state) extends PDOException {
            public function __construct(string $message, string $state)
            {
                parent::__construct($message);
                $this->code = $state;
            }
        };
        $queries = [];
        $store = new DatabaseNonceStore($this->connection($driver, $queries, $error), $this->nonceOptions());
        if (!$duplicate) {
            $this->expectException(NonceStoreUnavailableException::class);
        }
        self::assertTrue($store->seen('nonce'));
    }

    public function testMysqlAutocommitMustBeEnabledBeforeAnyStatementRuns(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('inTransaction')->willReturn(false);
        $pdo->method('getAttribute')->with(PDO::ATTR_AUTOCOMMIT)->willReturn(false);
        $pdo->expects(self::never())->method('prepare');
        $connection = new MySqlConnection($pdo, 'nonce_test', '', ['driver' => 'mysql', 'database' => 'nonce_test']);
        $this->expectException(NonceStoreConfigurationException::class);
        (new DatabaseNonceStore($connection, $this->nonceOptions()))->seen('nonce');
    }

    /** @param list<string> $queries */
    private function connection(string $driver, array &$queries, ?PDOException $insertError = null): Connection
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('inTransaction')->willReturn(false);
        $pdo->method('getAttribute')->willReturnCallback(static fn (int $attribute): mixed =>
            $attribute === PDO::ATTR_SERVER_VERSION ? '8.0.0' : true);
        $pdo->method('prepare')->willReturnCallback(function (string $query) use (&$queries, $insertError): PDOStatement {
            $queries[] = $query;
            $statement = $this->createMock(PDOStatement::class);
            if ($insertError !== null && str_starts_with($query, 'insert')) {
                $statement->method('execute')->willThrowException($insertError);
            } else {
                $statement->method('execute')->willReturn(true);
            }
            $statement->method('rowCount')->willReturn(str_starts_with($query, 'insert') ? 1 : 0);
            return $statement;
        });
        $config = ['driver' => $driver, 'database' => 'nonce_test'];
        return $driver === 'mysql'
            ? new MySqlConnection($pdo, 'nonce_test', '', $config)
            : new PostgresConnection($pdo, 'nonce_test', '', $config);
    }

    private function nonceOptions(): NonceStoreOptions
    {
        return NonceStoreOptions::fromArray(['namespace' => 'portability:test'], 300);
    }
}
