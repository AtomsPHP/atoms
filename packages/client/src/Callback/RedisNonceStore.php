<?php

declare(strict_types=1);

namespace Atoms\Client\Callback;

/** Redis owns expiry and the atomic claim. No application cache reads are used. */
final class RedisNonceStore implements NonceStore
{
    private const CLAIM = <<<'LUA'
        if redis.call('SET', KEYS[1], '1', 'NX', 'EX', ARGV[1]) then
            return 1
        end
        return 0
        LUA;

    /** @var \Closure(string, list<string>, int): mixed */
    private readonly \Closure $execute;

    /**
     * $execute receives the Lua script, its keys followed by arguments, and the key count.
     * It must execute immediately and return the Redis integer reply unchanged.
     *
     * @param callable(string, list<string>, int): mixed $execute
     */
    public function __construct(callable $execute, private readonly NonceStoreOptions $options)
    {
        $this->execute = \Closure::fromCallable($execute);
    }

    public static function fromClient(object $client, NonceStoreOptions $options): self
    {
        if (is_a($client, 'Redis') || is_a($client, 'RedisCluster')) {
            return new self(static function (string $script, array $arguments, int $keyCount) use ($client): mixed {
                // PhpRedis queues commands in MULTI/PIPELINE mode. Such a claim is not committed.
                if ($client->getMode() !== 0) {
                    throw new NonceStoreConfigurationException('Use a Redis connection outside transactions and pipelines for nonce storage.');
                }

                return $client->eval($script, $arguments, $keyCount);
            }, $options);
        }

        if (is_a($client, 'Predis\\ClientInterface')) {
            return new self(static fn (string $script, array $arguments, int $keyCount): mixed =>
                $client->eval($script, $keyCount, ...$arguments), $options);
        }

        throw new NonceStoreConfigurationException('The Redis nonce store requires a PhpRedis, RedisCluster, or Predis client.');
    }

    public function seen(string $nonce): bool
    {
        try {
            $result = ($this->execute)(self::CLAIM, [
                'atoms:callback-nonce:' . $this->options->key($nonce),
                (string) $this->options->retentionSeconds,
            ], 1);
        } catch (NonceStoreConfigurationException|NonceStoreUnavailableException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new NonceStoreUnavailableException($error);
        }

        if ($result !== 0 && $result !== 1) {
            // A queued command, server error, or lost response is never a successful claim.
            throw new NonceStoreUnavailableException();
        }

        return $result === 0;
    }
}
