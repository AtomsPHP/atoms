<?php

declare(strict_types=1);

namespace Atoms\Client\Exception;

/**
 * The platform (owning Machine or placement directory) was unreachable, or a
 * transport-level failure persisted across all retries (platform codes
 * `machine_unavailable` / `directory_unavailable`, or a local `transport`
 * failure). Retryable.
 */
final class PlatformUnavailable extends AtomsException
{
    public function __construct(
        string $message,
        string $platformCode = 'machine_unavailable',
        int $httpStatus = 502,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $message === '' ? 'The Atoms platform is currently unavailable.' : $message,
            $platformCode,
            true,
            $httpStatus,
            null,
            $previous,
        );
    }
}
