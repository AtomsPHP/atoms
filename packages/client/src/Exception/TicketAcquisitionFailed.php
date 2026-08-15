<?php

declare(strict_types=1);

namespace Atoms\Client\Exception;

use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;

/**
 * The platform refused to mint a WebSocket connection ticket, or the mint
 * response was unusable (ATOMS-E067). Covers `unauthenticated`,
 * `not_supported`, `invalid_request`, transport failures, and malformed
 * success bodies; an `unknown_atom_type` refusal maps to
 * {@see AtomNotDeployed} instead, exactly as it does for `/invoke`.
 */
final class TicketAcquisitionFailed extends AtomsException
{
    public function __construct(
        public readonly string $type,
        public readonly string $id,
        string $reason,
        string $platformCode,
        bool $retryable,
        int $httpStatus,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            ErrorCatalog::format(ErrorCode::WsTicketAcquisitionFailed, [
                'type' => $type,
                'id' => $id,
                'reason' => $reason,
            ]),
            $platformCode,
            $retryable,
            $httpStatus,
            ErrorCode::WsTicketAcquisitionFailed,
            $previous,
        );
    }
}
