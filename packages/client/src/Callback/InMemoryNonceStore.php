<?php

declare(strict_types=1);

namespace Atoms\Client\Callback;

/**
 * Instance-local LRU nonce store for explicit development and test use.
 *
 * A new store instance forgets prior requests, including under PHP-FPM.
 * Capacity eviction can also admit a replay before its timestamp expires.
 * Production callbacks require a shared store that retains unexpired records.
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
