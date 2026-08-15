<?php

declare(strict_types=1);

namespace Atoms\Client\Tests\Tickets;

use Atoms\Client\Tickets\Ticket;
use PHPUnit\Framework\TestCase;

final class TicketTest extends TestCase
{
    public function testExposesItsFieldsAndCastsToTheRawTicketString(): void
    {
        $ticket = new Ticket('v1.payload.sig', 1755200000000);

        self::assertSame('v1.payload.sig', $ticket->ticket);
        self::assertSame(1755200000000, $ticket->expiresAtMs);
        self::assertSame('v1.payload.sig', (string) $ticket);
    }
}
