<?php

declare(strict_types=1);

namespace Atoms\Client\Callback;

use Atoms\Errors\AtomsError;
use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;

final class NonceStoreConfigurationException extends AtomsError
{
    public function __construct(string $reason)
    {
        parent::__construct(
            ErrorCode::CallbackNonceStoreConfiguration,
            ErrorCatalog::format(ErrorCode::CallbackNonceStoreConfiguration) . ' ' . $reason,
        );
    }
}
