<?php

declare(strict_types=1);

namespace Atoms\Client\Exception;

/**
 * The platform rejected the request as malformed (platform code
 * `invalid_request`, HTTP 400). Not retryable — fix the call.
 */
final class InvalidRequest extends AtomsException
{
    public function __construct(string $message, int $httpStatus = 400, ?\Throwable $previous = null)
    {
        parent::__construct(
            $message === '' ? 'The platform rejected the request as malformed.' : $message,
            'invalid_request',
            false,
            $httpStatus,
            null,
            $previous,
        );
    }
}
