<?php

declare(strict_types=1);

namespace Atoms\Symfony\Tests\Callback;

use Atoms\Client\Callback\NonceStoreOptions;
use Atoms\Client\Callback\NonceStoreUnavailableException;
use Atoms\Symfony\Callback\DoctrineNonceStore;
use Atoms\Symfony\Migrations\Version20261029000000;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Driver\API\MySQL\ExceptionConverter as MySQLConverter;
use Doctrine\DBAL\Driver\API\PostgreSQL\ExceptionConverter as PostgreSQLConverter;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class DoctrinePortabilityTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function platforms(): iterable
    {
        yield 'MySQL' => ['mysql'];
        yield 'PostgreSQL' => ['pgsql'];
    }

    #[DataProvider('platforms')]
    public function testMigrationCompilesRequiredConstraintsForEachPlatform(string $platform): void
    {
        $migration = new Version20261029000000($this->connection($platform), new NullLogger());
        self::assertSame($platform !== 'mysql', $migration->isTransactional());
        $migration->up(new Schema());
        $sql = implode("\n", array_map(static fn ($query): string => $query->getStatement(), $migration->getSql()));

        self::assertStringContainsString('CREATE TABLE atoms_callback_nonces', $sql);
        self::assertStringContainsString('nonce_hash VARCHAR(64) NOT NULL', $sql);
        self::assertStringContainsString('expires_at BIGINT NOT NULL', $sql);
        self::assertMatchesRegularExpression('/UNIQUE (?:INDEX )?atoms_callback_nonces_nonce_hash_unique.*\(nonce_hash\)/', $sql);
        self::assertMatchesRegularExpression('/INDEX atoms_callback_nonces_expiry.*\(expires_at\)/', $sql);

        $rollback = new Version20261029000000($this->connection($platform), new NullLogger());
        $rollback->down(new Schema());
        self::assertSame('DROP TABLE atoms_callback_nonces', $rollback->getSql()[0]->getStatement());
    }

    /** @return iterable<string, array{string, string, int, bool}> */
    public static function databaseErrors(): iterable
    {
        yield 'MySQL duplicate' => ['mysql', '23000', 1062, true];
        yield 'MySQL another integrity error' => ['mysql', '23000', 1048, false];
        yield 'MySQL disconnected' => ['mysql', 'HY000', 2006, false];
        yield 'PostgreSQL duplicate' => ['pgsql', '23505', 0, true];
        yield 'PostgreSQL another integrity error' => ['pgsql', '23502', 0, false];
        yield 'PostgreSQL disconnected' => ['pgsql', '08006', 0, false];
    }

    #[DataProvider('databaseErrors')]
    public function testOnlyDriverNormalizedDuplicateErrorsAreReplays(string $platform, string $state, int $code, bool $duplicate): void
    {
        $error = new class ('Database error', $state, $code) extends AbstractException {};
        $store = new DoctrineNonceStore($this->connection($platform, $error), NonceStoreOptions::fromArray(['namespace' => 'test'], 300));
        if ($duplicate) {
            self::assertTrue($store->seen('nonce'));
            return;
        }

        try {
            $store->seen('nonce');
            self::fail('A database failure must not admit a callback or report a replay.');
        } catch (NonceStoreUnavailableException $failure) {
            self::assertInstanceOf(DriverException::class, $failure->getPrevious());
            self::assertNotInstanceOf(UniqueConstraintViolationException::class, $failure->getPrevious());
            self::assertSame($error, $failure->getPrevious()->getPrevious());
        }
    }

    #[DataProvider('platforms')]
    public function testInsertUsesOrdinarySqlAndRequiresAnAffectedRow(string $platform): void
    {
        $options = NonceStoreOptions::fromArray(['namespace' => 'test'], 300);
        self::assertFalse((new DoctrineNonceStore($this->connection($platform, null, 1), $options))->seen('nonce'));
        $this->expectException(NonceStoreUnavailableException::class);
        (new DoctrineNonceStore($this->connection($platform, null, 0), $options))->seen('nonce');
    }

    public function testDoctrineExecutorRunsMySqlMigrationWithoutWrappingDdlInTransaction(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
        $connection->expects(self::never())->method('beginTransaction');
        $connection->expects(self::never())->method('commit');
        $connection->expects(self::never())->method('rollBack');
        $connection->expects(self::once())->method('executeQuery')
            ->with(self::stringStartsWith('CREATE TABLE atoms_callback_nonces'))
            ->willReturn(new \Doctrine\DBAL\Result($this->createMock(Result::class), $connection));
        $metadata = $this->createMock(\Doctrine\Migrations\Metadata\Storage\MetadataStorage::class);
        $metadata->expects(self::once())->method('complete');
        $schemas = $this->createMock(\Doctrine\Migrations\Provider\SchemaDiffProvider::class);
        $schemas->method('createFromSchema')->willReturn(new Schema());
        $schemas->method('createToSchema')->willReturn(new Schema());
        $schemas->method('getSqlDiffToMigrate')->willReturn([]);
        $executor = new \Doctrine\Migrations\Version\DbalExecutor(
            $metadata,
            new \Doctrine\Migrations\EventDispatcher($connection, new \Doctrine\Common\EventManager()),
            $connection,
            $schemas,
            new NullLogger(),
            $this->createMock(\Doctrine\Migrations\ParameterFormatter::class),
            new \Symfony\Component\Stopwatch\Stopwatch(),
        );
        $plan = new \Doctrine\Migrations\Metadata\MigrationPlan(
            new \Doctrine\Migrations\Version\Version(Version20261029000000::class),
            new Version20261029000000($connection, new NullLogger()),
            \Doctrine\Migrations\Version\Direction::UP,
        );
        $result = $executor->execute($plan, new \Doctrine\Migrations\MigratorConfiguration());
        self::assertFalse($result->hasError());
        self::assertSame($result, $plan->getResult());
    }

    private function connection(string $platform, ?AbstractException $error = null, int $affected = 1): Connection
    {
        $statement = $this->createMock(Statement::class);
        if ($error !== null) {
            $statement->method('execute')->willThrowException($error);
        } else {
            $result = $this->createMock(Result::class);
            $result->method('rowCount')->willReturn($affected);
            $statement->method('execute')->willReturn($result);
        }

        $native = $this->createMock(NativeDriverConnection::class);
        $native->method('getNativeConnection')->willReturn(new \stdClass());
        $native->method('prepare')->with('INSERT INTO atoms_callback_nonces (nonce_hash, expires_at) VALUES (?, ?)')->willReturn($statement);
        $driver = $this->createMock(Driver::class);
        $driver->method('connect')->willReturn($native);
        $driver->method('getDatabasePlatform')->willReturn($platform === 'mysql' ? new MySQLPlatform() : new PostgreSQLPlatform());
        $driver->method('getExceptionConverter')->willReturn($platform === 'mysql' ? new MySQLConverter() : new PostgreSQLConverter());
        return new Connection(['driver' => 'pdo_' . $platform], $driver);
    }
}

interface NativeDriverConnection extends DriverConnection
{
    /** @return resource|object */
    public function getNativeConnection();
}
