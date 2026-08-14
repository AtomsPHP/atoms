<?php

/**
 * ATOMS-E084 — a dispatched job could not be encoded into its wire frame:
 * an empty class name, positional arguments where the wire form needs a map
 * keyed by constructor parameter name, or a value `json_encode()` refused.
 *
 * The runtime never reflects on the job — it never has the class — so this is
 * only ever about the `{"job":FQCN,"args":{...}}` frame the guest builds from
 * what `dispatch()` was handed.
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
