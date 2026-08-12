<?php

/**
 * ATOMS-E080 — `ATOMS_CALLBACK_URL` is unset. The feature exists; this
 * deployment has not been given an address (design doc §6.3).
 *
 * No declare(strict_types=1) — see the note in host.php.
 */

namespace Atoms\Cf;

use Atoms\Errors\ErrorCode;

final class CallbackNotConfigured extends CallbackError
{
    public static function create(): self
    {
        return new self(ErrorCode::CallbackChannelNotConfigured);
    }
}
