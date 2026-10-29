<?php

declare(strict_types=1);

namespace Atoms\Symfony\Callback;

use Atoms\Client\Callback\NonceStore;
use Atoms\Client\Callback\NonceStoreConfigurationException;
use Atoms\Client\Callback\NonceStoreOptions;
use Atoms\Client\Callback\NonceStoreUnavailableException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final class DoctrineNonceStore implements NonceStore
{
    public const TABLE = 'atoms_callback_nonces';

    public function __construct(
        private readonly Connection $connection,
        private readonly NonceStoreOptions $options,
    ) {
        DedicatedNonceConnectionFactory::assertPersistentDatabase($connection);
    }

    public function seen(string $nonce): bool
    {
        try {
            $this->assertAutocommit();
            $affected = $this->connection->insert(self::TABLE, [
                'nonce_hash' => $this->options->key($nonce),
                'expires_at' => time() + $this->options->retentionSeconds,
            ]);
            if ($affected !== 1 && $affected !== '1') {
                throw new NonceStoreUnavailableException();
            }

            return false;
        } catch (UniqueConstraintViolationException) {
            return true;
        } catch (NonceStoreConfigurationException|NonceStoreUnavailableException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new NonceStoreUnavailableException($error);
        }
    }

    public function prune(): int
    {
        try {
            $this->assertAutocommit();

            return (int) $this->connection->executeStatement(
                'DELETE FROM ' . self::TABLE . ' WHERE expires_at < ?',
                [time()],
            );
        } catch (NonceStoreConfigurationException|NonceStoreUnavailableException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new NonceStoreUnavailableException($error);
        }
    }

    private function assertAutocommit(): void
    {
        if (!$this->connection->isAutoCommit() || $this->connection->isTransactionActive()) {
            throw new NonceStoreConfigurationException('The nonce database connection must use autocommit without an active transaction.');
        }

        $native = $this->connection->getNativeConnection();
        if ($native instanceof \PDO) {
            if ($native->inTransaction()) {
                throw new NonceStoreConfigurationException('The nonce database connection must not have a native PDO transaction.');
            }
            if ($native->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' && !$native->getAttribute(\PDO::ATTR_AUTOCOMMIT)) {
                throw new NonceStoreConfigurationException('The native MySQL nonce connection must use autocommit.');
            }
        }
    }
}
