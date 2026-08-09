<?php

declare(strict_types=1);

namespace Atoms\Client\Callback;

/**
 * Bounded in-process LRU nonce store. Suitable for single-process deployments.
 *
 * NOTE: state lives in one PHP process only. Multi-process apps (php-fpm,
 * multiple web workers, or horizontally scaled instances) MUST supply a shared
 * {@see NonceStore} backed by Redis/Memcached/a database, or replays that land
 * on a different worker will slip through.
 */
final class InMemoryNonceStore implements NonceStore
{
    /** @var array<string, true> insertion-ordered; oldest first. */
    private array $nonces = [];

    public function __construct(private readonly int $maxEntries = 10000)
    {
    }

    public function seen(string $nonce): bool
    {
        if (isset($this->nonces[$nonce])) {
            // Refresh recency: move to the most-recent position.
            unset($this->nonces[$nonce]);
            $this->nonces[$nonce] = true;

            return true;
        }

        $this->nonces[$nonce] = true;

        while (count($this->nonces) > $this->maxEntries) {
            array_shift($this->nonces);
        }

        return false;
    }
}
