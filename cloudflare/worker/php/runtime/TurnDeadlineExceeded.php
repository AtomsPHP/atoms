<?php

/**
 * Reuses ATOMS-E061 (`Atoms\Errors\ErrorCode::TurnDeadlineExceeded`) — the
 * code, title and fix line already exist and already describe exactly this
 * failure; minting a second code for it is what append-only is meant to
 * prevent (design doc §9.2).
 *
 * Thrown by {@see CallbackAppProxy} when the host's `app.call` reply reports
 * `turn_deadline_exceeded`: the turn's budget for time spent awaiting the
 * callback channel is exhausted. Uncaught, `bootstrap.php`'s `run_turn()`
 * reports the turn-result code `turn_deadline_exceeded` rather than
 * `atom_exception` (design doc §2.4) — the one place this class's identity is
 * inspected rather than just its message.
 *
 * Deliberately shares its short name with `Atoms\Client\Exception\
 * TurnDeadlineExceeded`: same concept, two sides of the wire, and they can
 * never collide because the guest never loads atoms/client.
 *
 * No declare(strict_types=1) — see the note in host.php.
 */

namespace Atoms\Cf;

use Atoms\Errors\ErrorCode;

final class TurnDeadlineExceeded extends CallbackError
{
    /**
     * @param int $elapsedMs
     * @param int $budgetMs
     */
    public static function create($elapsedMs, $budgetMs): self
    {
        return new self(
            ErrorCode::TurnDeadlineExceeded,
            [],
            sprintf('elapsed %dms of a %dms turn budget', (int) $elapsedMs, (int) $budgetMs)
        );
    }
}
