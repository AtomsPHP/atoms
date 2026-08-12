<?php

/**
 * ATOMS-E081 — `ATOMS_CALLBACK_URL` is set but the Worker has no usable
 * Ed25519 signing key. There is no "development mode" that sends unsigned: a
 * monolith with the kernel mounted would reject it anyway (ATOMS-E064), so an
 * unsigned request would only make a security control look optional.
 *
 * No declare(strict_types=1) — see the note in host.php.
 */

namespace Atoms\Cf;

use Atoms\Errors\ErrorCode;

final class CallbackUnsigned extends CallbackError
{
    /** @param string $hostReason the host's diagnostic (e.g. "ATOMS_CALLBACK_SIGNING_KEY is not set") */
    public static function create($hostReason): self
    {
        return new self(ErrorCode::CallbackSigningKeyUnusable, [], (string) $hostReason);
    }
}
