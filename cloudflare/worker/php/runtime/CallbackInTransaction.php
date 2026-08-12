<?php

/**
 * ATOMS-E082 — `$this->app()` was called while a database transaction is
 * open. `ctx.storage.transactionSync(cb)` runs `cb` synchronously and cannot
 * await, so there is no version of this that works.
 *
 * Raised from two places: {@see CallbackAppProxy::__call()} (guest-side,
 * primary — the customer gets a clean exception and no request ever leaves
 * the Worker) and {@see CallbackChannel::exceptionFor()} mapping the host's
 * `tx_state` reply (defence in depth, should a prelude bug let one through).
 *
 * No declare(strict_types=1) — see the note in host.php.
 */

namespace Atoms\Cf;

use Atoms\Errors\ErrorCode;

final class CallbackInTransaction extends CallbackError
{
    /** @param string $method the app() method name that was refused */
    public static function for($method): self
    {
        return new self(ErrorCode::CallbackInTransaction, ['method' => (string) $method]);
    }
}
