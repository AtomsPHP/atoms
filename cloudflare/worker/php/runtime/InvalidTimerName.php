<?php

/**
 * ATOMS-E085 — `$this->timers()->schedule()` was given an invalid timer
 * name: empty, or over the runtime's `ATOMS_TIMER_NAME_MAX_BYTES` limit.
 * Raised by {@see CfTimers::schedule()} when the host's `timer.schedule`
 * reply reports `timer_invalid_name` (M2 wave 3).
 *
 * `\RuntimeException`, not `\PDOException`: this is not a database failure,
 * and matches how the callback channel's typed failures (CallbackError and
 * its subclasses) are thrown, formatted through the same catalog.
 *
 * No declare(strict_types=1) — see the note in host.php.
 */

namespace Atoms\Cf;

use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;

final class InvalidTimerName extends \RuntimeException
{
    /** @param string $name the invalid name that was rejected */
    public static function create($name): self
    {
        return new self(ErrorCatalog::format(ErrorCode::InvalidTimerName, [
            'name' => var_export((string) $name, true),
        ]));
    }
}
