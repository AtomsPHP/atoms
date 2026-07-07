<?php

declare(strict_types=1);

namespace Atoms\Client\Exception;

use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;

/**
 * The customer's own Atom code threw inside the platform runtime (ATOMS-E063).
 * The original PHP exception class and a sanitized remote stack trace are carried
 * through so the monolith can surface a meaningful error. Not retryable — the
 * same input will throw again.
 */
final class RemoteAtomException extends AtomsException
{
    public function __construct(
        string $type,
        string $id,
        string $method,
        public readonly string $originalClass,
        string $remoteMessage,
        public readonly ?string $remoteTrace = null,
        int $httpStatus = 500,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            ErrorCatalog::format(ErrorCode::RemoteAtomException, [
                'class' => $originalClass,
                'type' => $type === '' ? '?' : $type,
                'id' => $id === '' ? '?' : $id,
                'method' => $method === '' ? '?' : $method,
                'message' => $remoteMessage,
            ]),
            'remote_exception',
            false,
            $httpStatus,
            ErrorCode::RemoteAtomException,
            $previous,
        );
    }
}
