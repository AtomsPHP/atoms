<?php

declare(strict_types=1);

namespace Atoms\Client\Exception;

/**
 * Fallback for any platform failure that does not map to a more specific
 * exception (e.g. `unauthenticated`, `forbidden`, `internal`,
 * `deploy_in_progress`, `validation_failed`, unknown codes). Retryability
 * reflects the platform's `retryable` flag for the returned code.
 */
final class AtomsRequestFailed extends AtomsException
{
    public function __construct(
        string $message,
        string $platformCode,
        bool $retryable,
        int $httpStatus,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $message === '' ? sprintf('Request failed (%s).', $platformCode === '' ? 'unknown' : $platformCode) : $message,
            $platformCode,
            $retryable,
            $httpStatus,
            null,
            $previous,
        );
    }
}
