<?php

declare(strict_types=1);

namespace Atoms\Laravel\Tests\Testing;

use Atoms\Client\Tickets\Ticket;
use Atoms\Laravel\Facades\Atoms;
use Atoms\Laravel\Tests\Fixtures\GameRoom;
use Atoms\Laravel\Tests\TestCase;
use PHPUnit\Framework\ExpectationFailedException;

final class AtomsFakeTest extends TestCase
{
    public function testFakeNeverHitsHttpAndStubsByFqcnAndArrayForm(): void
    {
        $fake = Atoms::fake([
            GameRoom::class => [
                'ping' => 'pong',
            ],
        ]);

        $fake->stub(GameRoom::class, 'add', static fn (int $a, int $b): int => $a + $b);

        $room = Atoms::get(GameRoom::class, 'g-1');

        self::assertSame('pong', $room->ping());
        self::assertSame(7, $room->add(3, 4));
    }

    public function testStubbingByWireTypeStringWorksTheSameAsByFqcn(): void
    {
        $fake = Atoms::fake();
        $fake->stub('GameRoom', 'ping', 'pong-from-type-string');

        self::assertSame('pong-from-type-string', Atoms::get(GameRoom::class, 'g-1')->ping());
    }

    public function testAssertInvokedAndAssertNotInvokedWithClosureReceivesArgs(): void
    {
        $fake = Atoms::fake();
        $fake->stub(GameRoom::class, 'add', 0);

        Atoms::get(GameRoom::class, 'g-1')->add(3, 4);

        $fake->assertInvoked(GameRoom::class, 'add', static fn (int $a, int $b): bool => $a === 3 && $b === 4);
        $fake->assertNotInvoked(GameRoom::class, 'add', static fn (int $a, int $b): bool => $a === 99);
        $fake->assertNotInvoked(GameRoom::class, 'ping');
    }

    public function testAssertInvokedFailsWhenNotInvoked(): void
    {
        $fake = Atoms::fake();

        $this->expectException(ExpectationFailedException::class);
        $fake->assertInvoked(GameRoom::class, 'ping');
    }

    public function testInvocationsReturnsRawCallLog(): void
    {
        $fake = Atoms::fake();

        Atoms::get('GameRoom', 'g-1')->ping();

        $invocations = $fake->invocations();

        self::assertCount(1, $invocations);
        self::assertSame('GameRoom', $invocations[0]['type']);
        self::assertSame('g-1', $invocations[0]['id']);
        self::assertSame('ping', $invocations[0]['method']);
        self::assertSame([], $invocations[0]['args']);
    }

    public function testCallDelegatesThroughTheManagerWhenFaked(): void
    {
        $fake = Atoms::fake();
        $fake->stub(GameRoom::class, 'add', static fn (int $a, int $b): int => $a + $b);

        $result = Atoms::call('GameRoom', 'g-1', 'add', [10, 5], GameRoom::class);

        self::assertSame(15, $result);
        $fake->assertInvoked(GameRoom::class, 'add');
    }

    public function testTicketsAreRecordedAndAssertableWithoutAPsr18Fake(): void
    {
        $fake = Atoms::fake();

        $ticket = Atoms::ticket(GameRoom::class, 'g-1', ['client_id' => 'u-7']);

        self::assertInstanceOf(Ticket::class, $ticket);
        $fake->assertTicketIssued(GameRoom::class, 'g-1');
        $fake->assertTicketIssued(
            GameRoom::class,
            'g-1',
            static fn (array $claims): bool => $claims === ['client_id' => 'u-7'],
        );
        self::assertSame(
            [['type' => 'GameRoom', 'id' => 'g-1', 'claims' => ['client_id' => 'u-7']]],
            $fake->issuedTickets(),
        );
    }

    public function testAStubbedTicketIsReturnedVerbatim(): void
    {
        $fake = Atoms::fake();
        $fake->stubTicket(GameRoom::class, new Ticket('v1.pinned.sig', 1893456000000));

        $ticket = Atoms::ticket(GameRoom::class, 'g-1');

        self::assertSame('v1.pinned.sig', $ticket->ticket);
        self::assertSame(1893456000000, $ticket->expiresAtMs);
    }

    public function testAssertTicketIssuedFailsWhenTheScopeDiffers(): void
    {
        $fake = Atoms::fake();
        Atoms::ticket(GameRoom::class, 'g-1');

        $this->expectException(ExpectationFailedException::class);

        $fake->assertTicketIssued(GameRoom::class, 'g-2');
    }

    public function testDestroyTracksPresenceByTypeAndIdWithoutCreatingOnProxyOrTicket(): void
    {
        $fake = Atoms::fake();

        // Never-invoked ID is absent.
        self::assertFalse(Atoms::destroy(GameRoom::class, 'g-1'));

        // Obtaining a proxy or minting a ticket alone does not mark an Atom present.
        $proxy = Atoms::get(GameRoom::class, 'g-1');
        Atoms::ticket(GameRoom::class, 'g-1');
        self::assertFalse(Atoms::destroy(GameRoom::class, 'g-1'));

        // Invoking a method marks the (type, id) present.
        $proxy->ping();
        self::assertTrue(Atoms::destroy(GameRoom::class, 'g-1'));

        // Repeat destroy returns false until the ID is invoked again.
        self::assertFalse(Atoms::destroy(GameRoom::class, 'g-1'));

        // Reusing the same ID after destruction marks it present again.
        Atoms::call('GameRoom', 'g-1', 'ping');
        self::assertTrue(Atoms::destroy('GameRoom', 'g-1'));

        self::assertSame(
            [
                ['type' => 'GameRoom', 'id' => 'g-1', 'destroyed' => false],
                ['type' => 'GameRoom', 'id' => 'g-1', 'destroyed' => false],
                ['type' => 'GameRoom', 'id' => 'g-1', 'destroyed' => true],
                ['type' => 'GameRoom', 'id' => 'g-1', 'destroyed' => false],
                ['type' => 'GameRoom', 'id' => 'g-1', 'destroyed' => true],
            ],
            $fake->destroyed(),
        );
    }

    public function testDestroyIsolatesByTypeAndIdAndNormalizesUnloadedFqcn(): void
    {
        $fake = Atoms::fake();

        // Normalize even an unloaded FQCN consistently with AtomsClient::wireType().
        Atoms::call('App\\Atoms\\UnloadedRoom', 'r-1', 'ping');
        Atoms::get(GameRoom::class, 'r-1')->ping();
        Atoms::get(GameRoom::class, 'r-2')->ping();

        self::assertTrue(Atoms::destroy('UnloadedRoom', 'r-1'));
        self::assertFalse(Atoms::destroy('App\\Atoms\\UnloadedRoom', 'r-1'));

        // Other type and other ID remain untouched.
        self::assertTrue(Atoms::destroy(GameRoom::class, 'r-1'));
        self::assertTrue(Atoms::destroy('GameRoom', 'r-2'));
    }

    public function testAssertDestroyedAndAssertNotDestroyed(): void
    {
        $fake = Atoms::fake();

        Atoms::get(GameRoom::class, 'g-1')->ping();
        Atoms::destroy(GameRoom::class, 'g-1');

        $fake->assertDestroyed(GameRoom::class, 'g-1');
        $fake->assertDestroyed('GameRoom', 'g-1');
        $fake->assertNotDestroyed(GameRoom::class, 'g-2');
        $fake->assertNotDestroyed('OtherRoom', 'g-1');
    }

    public function testAssertDestroyedFailsWhenNotDestroyed(): void
    {
        $fake = Atoms::fake();

        $this->expectException(ExpectationFailedException::class);
        $fake->assertDestroyed(GameRoom::class, 'g-1');
    }

    public function testAssertNotDestroyedFailsWhenDestroyed(): void
    {
        $fake = Atoms::fake();
        Atoms::destroy(GameRoom::class, 'g-1');

        $this->expectException(ExpectationFailedException::class);
        $fake->assertNotDestroyed(GameRoom::class, 'g-1');
    }
}
