<?php

declare(strict_types=1);

namespace Atoms\Client\Tickets;

/**
 * A minted WebSocket connection ticket.
 *
 * Single-use and short-lived by contract: present it once as the `?ticket=`
 * query parameter on the Worker's `GET /ws/{type}/{id}` upgrade, and mint a
 * fresh one for every connection attempt — a ticket is consumed the moment
 * the upgrade reaches the atom, whether or not the socket was established,
 * so a reconnect always goes back through {@see TicketClient::acquire()}.
 */
final class Ticket
{
    public function __construct(
        /** The opaque ticket string, exactly as the Worker minted it. */
        public readonly string $ticket,
        /** Expiry as epoch milliseconds, echoed from the mint response. */
        public readonly int $expiresAtMs,
    ) {
    }

    public function __toString(): string
    {
        return $this->ticket;
    }
}
