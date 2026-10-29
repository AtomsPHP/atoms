<?php

declare(strict_types=1);

namespace Atoms\Client\Callback;

/**
 * Replay-protection store for callback nonces. Implementations must record and
 * answer atomically so two concurrent requests carrying the same nonce cannot
 * both be treated as first-seen. Shared stores must retain records through the
 * accepted callback timestamp interval. Return false only after committing the
 * claim; throw when storage is unavailable. Never replace a failed shared store
 * with an empty in-memory instance.
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
