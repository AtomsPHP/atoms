<?php

declare(strict_types=1);

namespace Atoms\Client\Callback;

use Atoms\Errors\AtomsError;
use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;

final class NonceStoreUnavailableException extends AtomsError
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct(
            ErrorCode::CallbackNonceStoreUnavailable,
            ErrorCatalog::format(ErrorCode::CallbackNonceStoreUnavailable),
            $previous,
        );
    }
}
