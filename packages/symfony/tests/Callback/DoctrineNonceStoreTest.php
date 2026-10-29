<?php

declare(strict_types=1);

namespace Atoms\Symfony\Tests\Callback;

use Atoms\Client\Callback\NonceStoreConfigurationException;
use Atoms\Client\Callback\NonceStoreOptions;
use Atoms\Client\Callback\NonceStoreUnavailableException;
use Atoms\Symfony\Callback\DedicatedNonceConnectionFactory;
use Atoms\Symfony\Callback\DoctrineNonceStore;
use Atoms\Symfony\Migrations\Version20261029000000;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Connections\PrimaryReadReplicaConnection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class DoctrineNonceStoreTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'atoms-symfony-nonces-');
        $connection = $this->connection();
        $migration = new Version20261029000000($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
        $connection->close();
    }

    protected function tearDown(): void
    {
        unlink($this->path);
    }

    public function testIndependentConnectionsRejectReplaysAndSeparateNamespaces(): void
    {
        self::assertFalse($this->store()->seen('same'));
        self::assertTrue($this->store()->seen('same'));
        self::assertFalse($this->store('another')->seen('same'));
        $expires = (int) $this->connection()->fetchOne('SELECT expires_at FROM atoms_callback_nonces LIMIT 1');
        self::assertGreaterThanOrEqual(time() + 660, $expires);
    }

    public function testPruneDeletesOnlyExpiredRows(): void
    {
        $connection = $this->connection();
        $connection->insert(DoctrineNonceStore::TABLE, ['nonce_hash' => 'expired', 'expires_at' => time() - 1]);
        self::assertFalse($this->store()->seen('unexpired'));
        self::assertSame(1, $this->store()->prune());
        self::assertTrue($this->store()->seen('unexpired'));
    }

    public function testMissingTableIsUnavailableRatherThanReplay(): void
    {
        $this->connection()->executeStatement('DROP TABLE atoms_callback_nonces');
        $this->expectException(NonceStoreUnavailableException::class);
        $this->store()->seen('nonce');
    }

    public function testApplicationTransactionsAreRejected(): void
    {
        $connection = $this->connection();
        $connection->beginTransaction();
        try {
            $this->expectException(NonceStoreConfigurationException::class);
            (new DoctrineNonceStore($connection, $this->options()))->seen('nonce');
        } finally {
            $connection->rollBack();
        }
    }

    public function testNativeTransactionsAreRejected(): void
    {
        $connection = $this->connection();
        $native = $connection->getNativeConnection();
        self::assertInstanceOf(\PDO::class, $native);
        $native->beginTransaction();
        try {
            $this->expectException(NonceStoreConfigurationException::class);
            (new DoctrineNonceStore($connection, $this->options()))->seen('nonce');
        } finally {
            $native->rollBack();
        }
    }

    public function testDisabledAutocommitIsRejectedBeforeConnecting(): void
    {
        $connection = $this->connection();
        $connection->setAutoCommit(false);
        $this->expectException(NonceStoreConfigurationException::class);
        (new DoctrineNonceStore($connection, $this->options()))->seen('nonce');
    }

    public function testFactoryDoesNotChangeOrReuseApplicationConnection(): void
    {
        $source = $this->connection();
        $source->setAutoCommit(false);
        $source->beginTransaction();
        $factory = DependencyFactory::fromConnection(new ConfigurationArray([]), new ExistingConnection($source));
        $dedicated = DedicatedNonceConnectionFactory::create($factory);
        try {
            self::assertNotSame($source, $dedicated);
            self::assertTrue($dedicated->isAutoCommit());
            self::assertFalse($source->isAutoCommit());
            self::assertTrue($source->isTransactionActive());
            self::assertNotSame($source->getNativeConnection(), $dedicated->getNativeConnection());
            self::assertFalse((new DoctrineNonceStore($dedicated, $this->options()))->seen('persisted'));
        } finally {
            $source->rollBack();
            $source->close();
        }
        self::assertTrue($this->store()->seen('persisted'));
    }

    public function testFactoryRejectsInMemorySqlite(): void
    {
        $source = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $factory = DependencyFactory::fromConnection(new ConfigurationArray([]), new ExistingConnection($source));
        $this->expectException(NonceStoreConfigurationException::class);
        DedicatedNonceConnectionFactory::create($factory);
    }

    public function testDirectConstructionRejectsInMemorySqlite(): void
    {
        $this->expectException(NonceStoreConfigurationException::class);
        new DoctrineNonceStore(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $this->options());
    }

    public function testInsertMustRecordExactlyOneRow(): void
    {
        $this->connection()->executeStatement(
            'CREATE TRIGGER ignore_nonce BEFORE INSERT ON atoms_callback_nonces BEGIN SELECT RAISE(IGNORE); END',
        );
        $this->expectException(NonceStoreUnavailableException::class);
        $this->store()->seen('not-recorded');
    }

    public function testFactoryOverridesNativeAutocommitAndPersistentOptions(): void
    {
        $source = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'path' => $this->path,
            'persistent' => true,
            'driverOptions' => [\PDO::ATTR_AUTOCOMMIT => false, \PDO::ATTR_PERSISTENT => true],
        ]);
        $factory = DependencyFactory::fromConnection(new ConfigurationArray([]), new ExistingConnection($source));
        $dedicated = DedicatedNonceConnectionFactory::create($factory);
        self::assertFalse($dedicated->getParams()['persistent']);
        self::assertFalse($dedicated->getParams()['driverOptions'][\PDO::ATTR_PERSISTENT]);
        self::assertTrue($dedicated->getParams()['driverOptions'][\PDO::ATTR_AUTOCOMMIT]);
        self::assertFalse($source->getParams()['driverOptions'][\PDO::ATTR_AUTOCOMMIT]);
    }

    public function testFactoryIsolatesPrimaryReplicaWriterFromApplicationRollback(): void
    {
        $source = $this->primaryReplicaConnection();
        $source->beginTransaction();
        $factory = DependencyFactory::fromConnection(new ConfigurationArray([]), new ExistingConnection($source));
        $dedicated = DedicatedNonceConnectionFactory::create($factory);
        try {
            $parameters = $dedicated->getParams();
            self::assertFalse($parameters['primary']['persistent']);
            self::assertFalse($parameters['primary']['driverOptions'][\PDO::ATTR_PERSISTENT]);
            self::assertTrue($parameters['primary']['driverOptions'][\PDO::ATTR_AUTOCOMMIT]);
            self::assertFalse($parameters['replica'][0]['driverOptions'][\PDO::ATTR_PERSISTENT]);
            self::assertTrue($parameters['replica'][0]['driverOptions'][\PDO::ATTR_AUTOCOMMIT]);
            self::assertTrue($source->getParams()['primary']['driverOptions'][\PDO::ATTR_PERSISTENT]);
            self::assertFalse((new DoctrineNonceStore($dedicated, $this->options()))->seen('persisted'));
            self::assertNotSame($source->getNativeConnection(), $dedicated->getNativeConnection());
        } finally {
            $source->rollBack();
            $source->close();
            $dedicated->close();
        }
        self::assertTrue($this->store()->seen('persisted'));
    }

    public function testDirectPrimaryReplicaStoreChecksWriterNativeTransaction(): void
    {
        $connection = $this->primaryReplicaConnection(false);
        $connection->ensureConnectedToPrimary();
        $primary = $connection->getNativeConnection();
        self::assertInstanceOf(\PDO::class, $primary);
        $primary->beginTransaction();
        $connection->ensureConnectedToReplica();
        self::assertNotSame($primary, $connection->getNativeConnection());
        try {
            $this->expectException(NonceStoreConfigurationException::class);
            (new DoctrineNonceStore($connection, $this->options()))->seen('nonce');
        } finally {
            $primary->rollBack();
            $connection->close();
        }
    }

    public function testPrimaryReplicaRejectsInMemoryWriter(): void
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'wrapperClass' => PrimaryReadReplicaConnection::class,
            'primary' => ['memory' => true],
            'replica' => [['path' => $this->path]],
        ]);
        $this->expectException(NonceStoreConfigurationException::class);
        new DoctrineNonceStore($connection, $this->options());
    }

    public function testWriterDoesNotConnectToUnavailableReplica(): void
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'wrapperClass' => PrimaryReadReplicaConnection::class,
            'primary' => ['path' => $this->path],
            'replica' => [['path' => $this->path . '/unavailable/database.sqlite']],
        ]);
        $store = new DoctrineNonceStore($connection, $this->options());
        self::assertFalse($store->seen('primary-only'));
        self::assertTrue($store->seen('primary-only'));
    }

    public function testFactoryDoesNotReapplyDbal3UrlPersistence(): void
    {
        if (!method_exists(Connection::class, 'getEventManager')) {
            self::markTestSkipped('DBAL 4 does not parse connection URLs.');
        }

        $source = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'wrapperClass' => PrimaryReadReplicaConnection::class,
            'primary' => ['url' => 'sqlite:///' . $this->path . '?persistent=1'],
            'replica' => [['url' => 'sqlite:///' . $this->path . '?persistent=1']],
        ]);
        $factory = DependencyFactory::fromConnection(new ConfigurationArray([]), new ExistingConnection($source));
        $dedicated = DedicatedNonceConnectionFactory::create($factory);
        $parameters = $dedicated->getParams();
        self::assertSame($this->path, $parameters['primary']['path']);
        self::assertFalse($parameters['primary']['persistent']);
        self::assertFalse($parameters['replica'][0]['persistent']);
        self::assertFalse((new DoctrineNonceStore($dedicated, $this->options()))->seen('url-persistent'));
        self::assertTrue($this->store()->seen('url-persistent'));
    }

    private function primaryReplicaConnection(bool $persistentReplica = true): PrimaryReadReplicaConnection
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'wrapperClass' => PrimaryReadReplicaConnection::class,
            'keepReplica' => true,
            'primary' => ['path' => $this->path, 'persistent' => true, 'driverOptions' => [\PDO::ATTR_PERSISTENT => true, \PDO::ATTR_AUTOCOMMIT => false]],
            'replica' => [['path' => $this->path, 'persistent' => $persistentReplica, 'driverOptions' => [\PDO::ATTR_PERSISTENT => $persistentReplica, \PDO::ATTR_AUTOCOMMIT => false]]],
        ]);
        self::assertInstanceOf(PrimaryReadReplicaConnection::class, $connection);
        return $connection;
    }

    private function connection(): Connection
    {
        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->path]);
    }

    private function options(string $namespace = 'test'): NonceStoreOptions
    {
        return NonceStoreOptions::fromArray(['namespace' => $namespace], 300);
    }

    private function store(string $namespace = 'test'): DoctrineNonceStore
    {
        return new DoctrineNonceStore($this->connection(), $this->options($namespace));
    }
}
