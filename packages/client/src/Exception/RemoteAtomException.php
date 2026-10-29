<?php

declare(strict_types=1);

namespace Atoms\Client\Exception;

use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;

/**
 * An Atom exception with its original class and message. Not retried.
 * Traces stay in Worker logs, so AtomsClient leaves remoteTrace null.
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
