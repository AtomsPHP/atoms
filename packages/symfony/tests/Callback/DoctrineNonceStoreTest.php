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
