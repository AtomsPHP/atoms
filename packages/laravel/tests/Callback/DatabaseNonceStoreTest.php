<?php

declare(strict_types=1);

namespace Atoms\Laravel\Tests\Callback;

use Atoms\Client\Callback\NonceStore;
use Atoms\Client\Callback\NonceStoreConfigurationException;
use Atoms\Client\Callback\NonceStoreOptions;
use Atoms\Client\Callback\NonceStoreUnavailableException;
use Atoms\Laravel\Callback\DatabaseNonceStore;
use Atoms\Laravel\Callback\NonceStoreFactory;
use Atoms\Laravel\Tests\TestCase;
use Illuminate\Support\Facades\Schema;

final class DatabaseNonceStoreTest extends TestCase
{
    protected bool $withMemoryNonceStore = false;

    private string $databasePath;

    protected function setUp(): void
    {
        $this->databasePath = tempnam(sys_get_temp_dir(), 'atoms-laravel-nonce-');
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        unlink($this->databasePath);
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('database.connections.nonce', [
            'driver' => 'sqlite',
            'database' => $this->databasePath,
            'prefix' => '',
        ]);
        $app['config']->set('atoms.callback_nonce.driver', 'database');
        $app['config']->set('atoms.callback_nonce.namespace', 'nonce-test');
        $app['config']->set('atoms.callback_nonce.database_connection', 'nonce');
    }

    public function testNormalMigrateCreatesTheTableOnTheSelectedConnection(): void
    {
        self::assertFalse(Schema::connection('nonce')->hasTable(DatabaseNonceStore::TABLE));
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        self::assertTrue(Schema::connection('nonce')->hasTable(DatabaseNonceStore::TABLE));
        self::assertFalse(Schema::hasTable(DatabaseNonceStore::TABLE));

        $this->artisan('migrate:rollback', ['--force' => true])->assertExitCode(0);
        self::assertFalse(Schema::connection('nonce')->hasTable(DatabaseNonceStore::TABLE));
    }

    public function testNewContainersShareRecordedNonces(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $first = $this->app->make(NonceStore::class);
        self::assertFalse($first->seen('signed-nonce'));
        $this->refreshApplication();

        $second = $this->app->make(NonceStore::class);
        self::assertNotSame($first, $second);
        self::assertTrue($second->seen('signed-nonce'));
        self::assertFalse($second->seen('another-nonce'));
    }

    public function testApplicationRollbackCannotRemoveTheNonce(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $applicationConnection = $this->app['db']->connection('nonce');
        $applicationConnection->beginTransaction();
        try {
            self::assertFalse($this->app->make(NonceStore::class)->seen('outside-transaction'));
        } finally {
            $applicationConnection->rollBack();
        }

        $this->refreshApplication();
        self::assertTrue($this->app->make(NonceStore::class)->seen('outside-transaction'));
    }

    public function testDirectlySuppliedTransactionalConnectionsAreRejected(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $connection = $this->app['db']->connection('nonce');
        $store = new DatabaseNonceStore($connection, $this->nonceOptions());
        $connection->getPdo()->beginTransaction();
        try {
            $this->expectException(NonceStoreConfigurationException::class);
            $store->seen('transaction-nonce');
        } finally {
            $connection->getPdo()->rollBack();
        }
    }

    public function testThePrivateConnectionDisablesPersistentPdoSessions(): void
    {
        config([
            'database.connections.nonce.read' => ['options' => [\PDO::ATTR_PERSISTENT => true]],
            'database.connections.nonce.write' => ['options' => [\PDO::ATTR_PERSISTENT => true, \PDO::ATTR_AUTOCOMMIT => false]],
        ]);
        $this->app->make(NonceStoreFactory::class)->database();
        $connections = array_filter($this->app['db']->getConnections(), static fn ($connection): bool =>
            str_starts_with($connection->getName(), '__atoms_callback_nonces_'));
        self::assertCount(1, $connections);
        $options = array_values($connections)[0]->getConfig('options');
        self::assertFalse($options[\PDO::ATTR_PERSISTENT]);
        self::assertArrayNotHasKey(\PDO::ATTR_AUTOCOMMIT, $options);
        self::assertArrayNotHasKey('read', array_values($connections)[0]->getConfig());
        self::assertArrayNotHasKey('write', array_values($connections)[0]->getConfig());
    }

    public function testDatabaseOutageDoesNotLookLikeADuplicate(): void
    {
        $this->expectException(NonceStoreUnavailableException::class);
        $this->app->make(NonceStore::class)->seen('missing-table');
    }

    public function testPruneDeletesOnlyExpiredRowsAndAnExpiredNonceCanBeRecordedAgain(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $store = $this->app->make(NonceStore::class);
        self::assertFalse($store->seen('expired'));
        self::assertFalse($store->seen('live'));
        $table = $this->app['db']->connection('nonce')->table(DatabaseNonceStore::TABLE);
        $table->where('nonce_hash', $this->nonceOptions()->key('expired'))->update(['expires_at' => time() - 1]);
        $this->artisan('atoms:nonces:prune')->expectsOutputToContain('Deleted 1 expired callback nonces.')->assertExitCode(0);
        self::assertTrue($store->seen('live'));
        self::assertFalse($store->seen('expired'));
    }

    public function testExpiredNonceCanBeReclaimedWithoutWaitingForPrune(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $store = $this->app->make(NonceStore::class);
        self::assertFalse($store->seen('expired'));
        $this->app['db']->connection('nonce')->table(DatabaseNonceStore::TABLE)
            ->where('nonce_hash', $this->nonceOptions()->key('expired'))->update(['expires_at' => time() - 1]);
        self::assertFalse($store->seen('expired'));
        self::assertTrue($store->seen('expired'));
    }

    public function testAnIgnoredInsertCannotAcceptACallbackWithoutRecordingIt(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->app['db']->connection('nonce')->statement(
            'CREATE TRIGGER ignore_nonce BEFORE INSERT ON atoms_callback_nonces BEGIN SELECT RAISE(IGNORE); END',
        );
        $this->expectException(NonceStoreUnavailableException::class);
        $this->app->make(NonceStore::class)->seen('not-recorded');
    }

    public function testNamespaceSeparatesApplicationsSharingTheTable(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        self::assertFalse($this->app->make(NonceStore::class)->seen('same-nonce'));
        config(['atoms.callback_nonce.namespace' => 'other-application']);
        $other = $this->app->make(NonceStoreFactory::class)->create();
        self::assertFalse($other->seen('same-nonce'));
    }

    public function testMemoryDatabaseConfigurationIsRejected(): void
    {
        config(['database.connections.temporary' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        config(['atoms.callback_nonce.database_connection' => 'temporary']);
        $this->expectException(NonceStoreConfigurationException::class);
        $this->app->make(NonceStore::class)->seen('nonce');
    }

    private function nonceOptions(): NonceStoreOptions
    {
        return NonceStoreOptions::fromArray(config('atoms.callback_nonce'), 300);
    }
}
