<?php

/**
 * ATOMS-E086 — this Atom already has `ATOMS_TIMERS_MAX` scheduled timers.
 * Raised by {@see CfTimers::schedule()} when the host's `timer.schedule`
 * reply reports `timer_limit`.
 *
 * No declare(strict_types=1) — see the note in host.php.
 */

namespace Atoms\Cf;

use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;

final class TimerLimitExceeded extends \RuntimeException
{
    /**
     * @param array{type: string, id: string} $identity
     * @param int $count the number of timers already scheduled, from the host's reply
     */
    public static function create(array $identity, $count): self
    {
        return new self(ErrorCatalog::format(ErrorCode::TimerLimitExceeded, [
            'type' => $identity['type'],
            'id' => $identity['id'],
            'count' => (string) (int) $count,
        ]));
    }
}
