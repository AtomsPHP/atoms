<?php

declare(strict_types=1);

namespace Atoms\Client\Exception;

use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;

/**
 * The Atom's turn exceeded the configured deadline (platform code
 * `turn_deadline_exceeded`, ATOMS-E061). The platform flags it retryable, but
 * the client never auto-retries it: everything the turn wrote before the
 * overrun stays committed, so only the caller knows whether running the method
 * again is safe. Catch this and call again when it is.
 */
final class TurnDeadlineExceeded extends AtomsException
{
    public function __construct(int $httpStatus = 504, ?\Throwable $previous = null)
    {
        parent::__construct(
            ErrorCatalog::format(ErrorCode::TurnDeadlineExceeded),
            'turn_deadline_exceeded',
            false,
            $httpStatus,
            ErrorCode::TurnDeadlineExceeded,
            $previous,
        );
    }
}
