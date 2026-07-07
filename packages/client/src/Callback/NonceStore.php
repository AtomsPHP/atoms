<?php

declare(strict_types=1);

namespace Atoms\Client\Callback;

/**
 * Replay-protection store for callback nonces. Implementations must record and
 * answer atomically so two concurrent requests carrying the same nonce cannot
 * both be treated as first-seen.
 */
interface NonceStore
{
    /**
     * Record $nonce and report whether it had already been seen.
     *
     * @return bool true if $nonce was seen before (a replay); false if it is new
     *              (and has now been recorded).
     */
    public function seen(string $nonce): bool;
}
