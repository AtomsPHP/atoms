<?php

declare(strict_types=1);

namespace Atoms\Client\Exception;

use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;

/**
 * Your own Atom code threw inside the Worker runtime (ATOMS-E063).
 * Carries the original PHP exception class and message. The Worker logs traces
 * server-side; remoteTrace is null for exceptions created by AtomsClient.
 * Not retryable — the same input will throw again.
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
