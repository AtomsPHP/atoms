<?php

declare(strict_types=1);

namespace Atoms\Errors;

/**
 * Base exception for every Atoms failure. Carries a stable ErrorCode so callers
 * (and the agent tooling) can branch on the machine-readable code rather than
 * the human message.
 */
class AtomsError extends \RuntimeException
{
    public readonly ErrorCode $errorCode;

    public function __construct(ErrorCode $code, string $message = '', ?\Throwable $previous = null)
    {
        $this->errorCode = $code;

        if ($message === '') {
            $message = ErrorCatalog::get($code)->title;
        }

        parent::__construct($message, 0, $previous);
    }
}
