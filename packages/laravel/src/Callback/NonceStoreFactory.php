<?php

declare(strict_types=1);

namespace Atoms\Laravel\Callback;

use Atoms\Client\Callback\InMemoryNonceStore;
use Atoms\Client\Callback\NonceStore;
use Atoms\Client\Callback\NonceStoreConfigurationException;
use Atoms\Client\Callback\NonceStoreOptions;
use Atoms\Client\Callback\NonceStoreUnavailableException;
use Atoms\Client\Callback\RedisNonceStore;
use Atoms\Client\Callback\UnconfiguredNonceStore;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Database\DatabaseManager;

final class NonceStoreFactory
{
    public function __construct(private readonly Application $app)
    {
    }

    public function create(): NonceStore
    {
        $config = $this->configuration();

        return match ($config['driver'] ?? null) {
            null, '' => new UnconfiguredNonceStore(),
            'memory' => new InMemoryNonceStore(),
            'database' => $this->database(),
            'redis' => $this->redis($config),
            default => throw new NonceStoreConfigurationException('Choose database, redis, or memory as the callback nonce driver.'),
        };
    }

    public function database(): DatabaseNonceStore
    {
        $config = $this->configuration();
        $options = $this->options($config);

        try {
            /** @var DatabaseManager $manager */
            $manager = $this->app->make('db');
            $name = $config['database_connection'] ?? $manager->getDefaultConnection();
            if (!is_string($name) || $name === '') {
                throw new NonceStoreConfigurationException('Set a valid callback nonce database connection name.');
            }

            $source = $manager->connection($name)->getConfig();
            $internalName = '__atoms_callback_nonces_' . hash('sha256', $name);
            unset($source['name']);
            $source['options'][\PDO::ATTR_PERSISTENT] = false;
            unset($source['options'][\PDO::ATTR_AUTOCOMMIT]);
            if (in_array($source['driver'] ?? null, ['mysql', 'mariadb'], true)) {
                $source['options'][\PDO::ATTR_AUTOCOMMIT] = true;
            }
            $this->app['config']->set('database.connections.' . $internalName, $source);
            $connection = $manager->connection($internalName);

            return new DatabaseNonceStore($connection, $options);
        } catch (NonceStoreConfigurationException $error) {
            throw $error;
        } catch (\InvalidArgumentException $error) {
            throw new NonceStoreConfigurationException('The callback nonce database connection is not configured.');
        } catch (\Throwable $error) {
            throw new NonceStoreUnavailableException($error);
        }
    }

    /** @param array<string, mixed> $config */
    private function redis(array $config): NonceStore
    {
        $options = $this->options($config);
        $name = $config['redis_connection'] ?? 'default';
        if (!is_string($name) || $name === '') {
            throw new NonceStoreConfigurationException('Set a valid callback nonce Redis connection name.');
        }

        try {
            $connection = $this->app->make(RedisFactory::class)->connection($name);

            return RedisNonceStore::fromClient($connection->client(), $options);
        } catch (NonceStoreConfigurationException $error) {
            throw $error;
        } catch (\InvalidArgumentException $error) {
            throw new NonceStoreConfigurationException('The callback nonce Redis connection is not configured.');
        } catch (\Throwable $error) {
            throw new NonceStoreUnavailableException($error);
        }
    }

    /** @param array<string, mixed> $config */
    private function options(array $config): NonceStoreOptions
    {
        return NonceStoreOptions::fromArray($config, (int) $this->app['config']->get('atoms.callback_timestamp_window', 300));
    }

    /** @return array<string, mixed> */
    private function configuration(): array
    {
        $config = $this->app['config']->get('atoms.callback_nonce', []);
        if (!is_array($config)) {
            throw new NonceStoreConfigurationException('Configure callback_nonce as an array.');
        }

        return $config;
    }
}
