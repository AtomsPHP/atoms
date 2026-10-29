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

            // DBAL 3 has already resolved URLs into the source parameters.
            // Re-parsing them would override the isolation settings below.
            if (method_exists($source, 'getEventManager')) {
                $parameters = self::withoutResolvedUrl($parameters);
                if (isset($parameters['primary'])) {
                    $parameters['primary'] = self::withoutResolvedUrl($parameters['primary']);
                }
                if (isset($parameters['replica']) && is_array($parameters['replica'])) {
                    foreach ($parameters['replica'] as $key => $replica) {
                        $parameters['replica'][$key] = self::withoutResolvedUrl($replica);
                    }
                }
            }

            $driver = $parameters['driver'] ?? '';
            $parameters = self::nonPersistentParameters($parameters, $driver);
            if (isset($parameters['primary']) && is_array($parameters['primary'])) {
                $parameters['primary'] = self::nonPersistentParameters($parameters['primary'], $driver);
            }
            if (isset($parameters['replica']) && is_array($parameters['replica'])) {
                foreach ($parameters['replica'] as $key => $replica) {
                    $parameters['replica'][$key] = self::nonPersistentParameters($replica, $driver);
                }
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

    /**
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    private static function withoutResolvedUrl(array $parameters): array
    {
        unset($parameters['url']);
        return $parameters;
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    private static function nonPersistentParameters(array $parameters, string $driver): array
    {
        $parameters['persistent'] = false;
        if (str_starts_with($parameters['driver'] ?? $driver, 'pdo_')) {
            $parameters['driverOptions'][\PDO::ATTR_PERSISTENT] = false;
            $parameters['driverOptions'][\PDO::ATTR_AUTOCOMMIT] = true;
        }

        return $parameters;
    }

    public static function assertPersistentDatabase(Connection $connection): void
    {
        $parameters = $connection->getParams();
        if (in_array($parameters['driver'] ?? '', ['pdo_sqlite', 'sqlite3'], true)) {
            $writer = $parameters['primary'] ?? $parameters;
            $path = $writer['path'] ?? '';
            if (($writer['memory'] ?? false) || $path === '' || str_contains($path, ':memory:') || str_contains($path, 'mode=memory')) {
                throw new NonceStoreConfigurationException('Database nonce storage needs a persistent SQLite file, not an in-memory database.');
            }
        }
    }
}
