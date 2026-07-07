<?php

declare(strict_types=1);

namespace Atoms\Client\Exception;

use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;

/**
 * The platform refused the request under admission control or rate limiting
 * (platform codes `capacity_refused` / `rate_limited`, ATOMS-E062). Retryable;
 * carries the server's Retry-After (seconds) when supplied.
 */
final class CapacityRefused extends AtomsException
{
    public function __construct(
        string $reason,
        string $platformCode,
        int $httpStatus,
        public readonly ?int $retryAfter = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            ErrorCatalog::format(ErrorCode::CapacityRefused, ['reason' => $reason === '' ? 'at capacity' : $reason]),
            $platformCode,
            true,
            $httpStatus,
            ErrorCode::CapacityRefused,
            $previous,
        );
    }
}
