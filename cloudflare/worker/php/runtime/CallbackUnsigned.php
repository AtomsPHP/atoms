<?php

/**
 * ATOMS-E081 — `ATOMS_CALLBACK_URL` is set but the Worker cannot derive a
 * callback signing key, because `ATOMS_SHARED_SECRET` is missing or does not
 * decode to exactly 32 bytes of base64. There is no "development mode" that
 * sends unsigned: a monolith with the kernel mounted would reject it anyway
 * (ATOMS-E064), so an unsigned request would only make a security control look
 * optional.
 *
 * No declare(strict_types=1) — see the note in host.php.
 */

namespace Atoms\Cf;

use Atoms\Errors\ErrorCode;

final class CallbackUnsigned extends CallbackError
{
    /** @param string $hostReason the host's diagnostic (e.g. "the callback signing key is derived from ATOMS_SHARED_SECRET ...") */
    public static function create($hostReason): self
    {
        return new self(ErrorCode::CallbackSigningKeyUnusable, [], (string) $hostReason);
    }
}
