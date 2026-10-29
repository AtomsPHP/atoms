<?php

declare(strict_types=1);

namespace Atoms\Symfony\Callback;

use Atoms\Client\Callback\NonceStoreConfigurationException;
use Atoms\Client\Callback\NonceStoreUnavailableException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\Migrations\DependencyFactory;

final class DedicatedNonceConnectionFactory
{
    public static function create(DependencyFactory $migrations): Connection
    {
        try {
            $source = $migrations->getConnection();
            $parameters = $source->getParams();
            self::assertPersistentDatabase($source);

            $parameters['persistent'] = false;
            if (str_starts_with($parameters['driver'] ?? '', 'pdo_')) {
                $parameters['driverOptions'][\PDO::ATTR_PERSISTENT] = false;
                $parameters['driverOptions'][\PDO::ATTR_AUTOCOMMIT] = true;
            }
            $configuration = clone $source->getConfiguration();
            $configuration->setAutoCommit(true);

            // DBAL 3 connection listeners can configure the database session.
            if (method_exists($source, 'getEventManager')) {
                $connect = new \ReflectionMethod(DriverManager::class, 'getConnection');
                $connection = $connect->invokeArgs(null, [$parameters, $configuration, $source->getEventManager()]);
                if (!$connection instanceof Connection) {
                    throw new NonceStoreConfigurationException('Doctrine did not create a database connection.');
                }
                return $connection;
            }

            return DriverManager::getConnection($parameters, $configuration);
        } catch (NonceStoreConfigurationException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new NonceStoreUnavailableException($error);
        }
    }

    public static function assertPersistentDatabase(Connection $connection): void
    {
        $parameters = $connection->getParams();
        if (in_array($parameters['driver'] ?? '', ['pdo_sqlite', 'sqlite3'], true)) {
            $path = $parameters['path'] ?? '';
            if (($parameters['memory'] ?? false) || $path === '' || str_contains($path, ':memory:') || str_contains($path, 'mode=memory')) {
                throw new NonceStoreConfigurationException('Database nonce storage needs a persistent SQLite file, not an in-memory database.');
            }
        }
    }
}
