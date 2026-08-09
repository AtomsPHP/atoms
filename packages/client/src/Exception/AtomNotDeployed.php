<?php

declare(strict_types=1);

namespace Atoms\Client\Exception;

use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;

/**
 * The requested Atom type is not present in the customer's current deploy
 * manifest (platform code `unknown_atom_type`, ATOMS-E060). Not retryable.
 */
final class AtomNotDeployed extends AtomsException
{
    public function __construct(
        public readonly string $type,
        int $httpStatus = 404,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            ErrorCatalog::format(ErrorCode::AtomTypeNotDeployed, ['type' => $type]),
            'unknown_atom_type',
            false,
            $httpStatus,
            ErrorCode::AtomTypeNotDeployed,
            $previous,
        );
    }
}
