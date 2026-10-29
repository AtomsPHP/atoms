<?php

declare(strict_types=1);

namespace Atoms\Symfony\DependencyInjection;

use Atoms\Client\Callback\InMemoryNonceStore;
use Atoms\Client\Callback\NonceStore;
use Atoms\Client\Callback\NonceStoreConfigurationException;
use Atoms\Client\Callback\NonceStoreOptions;
use Atoms\Client\Callback\NonceStoreUnavailableException;
use Atoms\Client\Callback\RedisNonceStore;
use Atoms\Client\Callback\UnconfiguredNonceStore;
use Atoms\Symfony\Callback\DedicatedNonceConnectionFactory;
use Atoms\Symfony\Callback\DoctrineNonceStore;
use Doctrine\Migrations\DependencyFactory;

final class NonceStoreFactory
{
    /**
     * @param array<string, mixed> $config
     * @param (\Closure(): mixed)|null $redis
     * @param (\Closure(): mixed)|null $migrations
     */
    public function __construct(
        private readonly array $config,
        private readonly int $timestampWindow,
        private readonly ?\Closure $redis = null,
        private readonly ?\Closure $migrations = null,
    ) {
    }

    public function create(): NonceStore
    {
        $driver = $this->config['driver'] ?? null;
        if ($driver === null || $driver === '') {
            return new UnconfiguredNonceStore();
        }
        if ($driver === 'memory') {
            return new InMemoryNonceStore();
        }
        if (!in_array($driver, ['redis', 'database'], true)) {
            throw new NonceStoreConfigurationException('Set atoms.callback_nonce.driver to redis, database, or memory.');
        }

        $options = NonceStoreOptions::fromArray($this->config, $this->timestampWindow);
        try {
            if ($driver === 'redis') {
                $redis = $this->redis !== null ? ($this->redis)() : null;
                if (!is_object($redis)) {
                    throw new NonceStoreConfigurationException('Set atoms.callback_nonce.redis_service to an existing Redis or Predis connection service.');
                }
                return RedisNonceStore::fromClient($redis, $options);
            }

            $migrations = $this->migrations !== null ? ($this->migrations)() : null;
            if (!$migrations instanceof DependencyFactory) {
                throw new NonceStoreConfigurationException('Install and enable DoctrineMigrationsBundle to use database nonce storage.');
            }
            return new DoctrineNonceStore(DedicatedNonceConnectionFactory::create($migrations), $options);
        } catch (NonceStoreConfigurationException|NonceStoreUnavailableException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new NonceStoreUnavailableException($error);
        }
    }
}
