<?php

declare(strict_types=1);

namespace Atoms\Laravel\Callback;

use Atoms\Client\Callback\NonceStore;
use Atoms\Client\Callback\NonceStoreConfigurationException;
use Atoms\Client\Callback\NonceStoreOptions;
use Atoms\Client\Callback\NonceStoreUnavailableException;
use Illuminate\Database\Connection;
use Illuminate\Database\UniqueConstraintViolationException;

final class DatabaseNonceStore implements NonceStore
{
    public const TABLE = 'atoms_callback_nonces';

    public function __construct(
        private readonly Connection $connection,
        private readonly NonceStoreOptions $options,
    ) {
    }

    public function seen(string $nonce): bool
    {
        try {
            $this->requireAutocommit();
            $key = $this->options->key($nonce);
            $now = time();
            $this->connection->table(self::TABLE)
                ->where('nonce_hash', $key)
                ->where('expires_at', '<', $now)
                ->delete();

            try {
                $query = $this->connection->table(self::TABLE);
                $values = ['nonce_hash' => $key, 'expires_at' => $now + $this->options->retentionSeconds];
                // Query Builder insert() reports execution success, not whether a row was stored.
                $inserted = $this->connection->affectingStatement(
                    $query->getGrammar()->compileInsert($query, $values),
                    array_values($values),
                );
            } catch (UniqueConstraintViolationException) {
                return true;
            }

            if ($inserted !== 1) {
                throw new NonceStoreUnavailableException();
            }

            return false;
        } catch (NonceStoreConfigurationException | NonceStoreUnavailableException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new NonceStoreUnavailableException($error);
        }
    }

    public function prune(): int
    {
        try {
            $this->requireAutocommit();

            return $this->connection->table(self::TABLE)
                ->where('expires_at', '<', time())
                ->delete();
        } catch (NonceStoreConfigurationException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new NonceStoreUnavailableException($error);
        }
    }

    private function requireAutocommit(): void
    {
        $database = $this->connection->getConfig('database');
        if ($this->connection->getDriverName() === 'sqlite'
            && (!is_string($database) || $database === '' || str_contains(strtolower($database), ':memory:')
                || str_contains(strtolower($database), 'mode=memory'))) {
            throw new NonceStoreConfigurationException('The callback nonce database needs a persistent SQLite file.');
        }

        $pdo = $this->connection->getPdo();
        if ($this->connection->transactionLevel() !== 0 || $pdo->inTransaction()
            || (in_array($this->connection->getDriverName(), ['mysql', 'mariadb'], true)
                && !$pdo->getAttribute(\PDO::ATTR_AUTOCOMMIT))) {
            throw new NonceStoreConfigurationException('The nonce database connection must use autocommit.');
        }

        if ($this->connection->pretending()) {
            throw new NonceStoreConfigurationException('The nonce database connection cannot run in pretend mode.');
        }
    }
}
