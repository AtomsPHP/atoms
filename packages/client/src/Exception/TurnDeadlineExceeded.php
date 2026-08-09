<?php

declare(strict_types=1);

namespace Atoms\Client\Exception;

use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;

/**
 * The Atom's turn exceeded the configured deadline (platform code
 * `turn_deadline_exceeded`, ATOMS-E061). Retryable at the platform level, but
 * the client only auto-retries when the call site opts in.
 */
final class TurnDeadlineExceeded extends AtomsException
{
    public function __construct(int $httpStatus = 504, ?\Throwable $previous = null)
    {
        parent::__construct(
            ErrorCatalog::format(ErrorCode::TurnDeadlineExceeded),
            'turn_deadline_exceeded',
            true,
            $httpStatus,
            ErrorCode::TurnDeadlineExceeded,
            $previous,
        );
    }
}
