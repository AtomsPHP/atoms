<?php

declare(strict_types=1);

namespace Atoms\Client\Tickets;

/**
 * A WebSocket connection ticket, issued by {@see TicketIssuer::issue()}.
 *
 * Present it as the `?ticket=` query parameter on the Worker's
 * `GET /ws/{type}/{id}` upgrade. It is **reusable until it expires** — not
 * single-use — so a reconnect inside the lifetime can retry the same URL
 * without going back to the server; nothing is claimed or burned on connect,
 * and the whole check stays stateless at the edge.
 *
 * The short lifetime is therefore the defence against a leaked URL. Because a
 * browser cannot read why an upgrade failed, the client rule is simply: on any
 * connection failure, issue a fresh one.
 */
final class Ticket
{
    public function __construct(
        /** The opaque ticket string: `v1.<base64url payload>.<base64url signature>`. */
        public readonly string $ticket,
        /** Expiry as epoch milliseconds — the `exp` signed into the ticket. */
        public readonly int $expiresAtMs,
    ) {
    }

    public function __toString(): string
    {
        return $this->ticket;
    }
}
