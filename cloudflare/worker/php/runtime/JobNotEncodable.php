<?php

/**
 * ATOMS-E084 — a dispatched {@see \Atoms\AtomJob}'s constructor parameters
 * could not be read back off the object. The constructor's parameters ARE the
 * dispatch contract (`AtomJob`'s own docblock) and must be promoted public,
 * non-static properties; anything else cannot be read here without an
 * instance the runtime does not have.
 *
 * No declare(strict_types=1) — see the note in host.php.
 */

namespace Atoms\Cf;

use Atoms\Errors\ErrorCode;

final class JobNotEncodable extends CallbackError
{
    /**
     * @param string $class
     * @param string $param
     */
    public static function missingProperty($class, $param): self
    {
        return new self(ErrorCode::JobNotEncodable, [
            'class' => (string) $class,
            'reason' => sprintf('constructor parameter "%s" has no matching property', (string) $param),
        ]);
    }

    /**
     * @param string $class
     * @param string $param
     */
    public static function notPublic($class, $param): self
    {
        return new self(ErrorCode::JobNotEncodable, [
            'class' => (string) $class,
            'reason' => sprintf(
                'the property backing constructor parameter "%s" is not a public instance property',
                (string) $param
            ),
        ]);
    }

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
