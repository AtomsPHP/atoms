<?php

declare(strict_types=1);

namespace Atoms\Client\Exception;

use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;

/**
 * A WebSocket ticket could not be issued because the scope or claims handed to
 * {@see \Atoms\Client\Tickets\TicketIssuer::issue()} do not fit the protocol
 * (ATOMS-E068, docs/ws-ticket-protocol.md).
 *
 * This is a programming error in the calling code, not a platform failure —
 * nothing was sent anywhere, and retrying the same input cannot help — so it
 * extends \InvalidArgumentException rather than {@see AtomsException}, which
 * models the wire (a platform code, a retryable flag, an HTTP status). The
 * closest sibling is {@see \Atoms\Client\Crypto\KeyDerivation::decodeSecret()}
 * refusing a malformed secret.
 */
final class InvalidTicketClaims extends \InvalidArgumentException
{
    public function __construct(
        public readonly string $type,
        public readonly string $id,
        string $reason,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            ErrorCatalog::format(ErrorCode::WsTicketClaimsInvalid, [
                'type' => $type,
                'id' => $id,
                'reason' => $reason,
            ]),
            0,
            $previous,
        );
    }
}
