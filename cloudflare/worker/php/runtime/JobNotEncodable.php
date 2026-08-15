<?php

/**
 * ATOMS-E084 — a dispatched job could not be encoded into its
 * `{"job":FQCN,"args":{...}}` frame: an empty class name, positional arguments
 * where the wire form needs a map keyed by parameter name, or a value
 * `json_encode()` refused.
 *
 * No declare(strict_types=1) — see the note in host.php.
 */

namespace Atoms\Cf;

use Atoms\Errors\ErrorCode;

final class JobNotEncodable extends CallbackError
{
    /**
     * @param string $class
     * @param string $reason
     */
    public static function unencodable($class, $reason): self
    {
        return new self(ErrorCode::JobNotEncodable, [
            'class' => (string) $class,
            'reason' => (string) $reason,
        ]);
    }
}
