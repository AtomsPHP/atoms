<?php

declare(strict_types=1);

namespace Atoms\Client\Callback;

/** Connect to the nonce backend only after a callback passes authentication. */
final class LazyNonceStore implements NonceStore
{
    /** @var \Closure(): NonceStore */
    private readonly \Closure $factory;

    private ?NonceStore $store = null;

    /** @param callable(): NonceStore $factory */
    public function __construct(callable $factory)
    {
        $this->factory = \Closure::fromCallable($factory);
    }

    public function seen(string $nonce): bool
    {
        try {
            $this->store ??= ($this->factory)();

            return $this->store->seen($nonce);
        } catch (NonceStoreConfigurationException|NonceStoreUnavailableException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new NonceStoreUnavailableException($error);
        }
    }
}
