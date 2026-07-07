<?php

declare(strict_types=1);

namespace Atoms\Client\Exception;

use Atoms\Errors\ErrorCode;

/**
 * Base class for every failure surfaced by the client when talking to the
 * platform. Carries the platform wire code (`unknown_atom_type`, ...), whether
 * the platform considered the failure retryable, the HTTP status, and — where a
 * catalog code applies — the {@see ErrorCode}.
 */
abstract class AtomsException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $platformCode,
        public readonly bool $retryable,
        public readonly int $httpStatus,
        public readonly ?ErrorCode $errorCode = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
