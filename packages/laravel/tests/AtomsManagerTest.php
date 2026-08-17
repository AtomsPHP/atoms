<?php

declare(strict_types=1);

namespace Atoms\Laravel\Tests;

use Atoms\Client\AtomsClient;
use Atoms\Client\Exception\InvalidTicketClaims;
use Atoms\Client\Tickets\Ticket;
use Atoms\Laravel\AtomsManager;
use Atoms\Laravel\Facades\Atoms;
use Atoms\Laravel\Tests\Fixtures\GameRoom;

final class AtomsManagerTest extends TestCase
{
    public function testTicketIssuesForTheAtomClassWithoutRepeatingItsWireType(): void
    {
        $ticket = Atoms::ticket(GameRoom::class, 'g-1', ['client_id' => 'u-7']);

        self::assertInstanceOf(Ticket::class, $ticket);
        self::assertStringStartsWith('v1.', $ticket->ticket);

        // The scope really is the class basename, not the FQCN.
        $payload = json_decode(
            (string) base64_decode(strtr(explode('.', $ticket->ticket)[1], '-_', '+/'), true),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($payload);
        self::assertSame('GameRoom', $payload['t']);
        self::assertSame('g-1', $payload['i']);
        self::assertSame(['client_id' => 'u-7'], $payload['claims']);
    }

    public function testTicketHonoursAPerCallLifetime(): void
    {
        $short = Atoms::ticket(GameRoom::class, 'g-1', [], 5000);
        $default = Atoms::ticket(GameRoom::class, 'g-1');

        self::assertLessThan($default->expiresAtMs, $short->expiresAtMs);
    }

    public function testTicketRejectsAReservedClaimKey(): void
    {
        $this->expectException(InvalidTicketClaims::class);

        Atoms::ticket(GameRoom::class, 'g-1', ['channels' => 'lobby']);
    }

    public function testTicketWithoutAnIssuerNamesWhatToBind(): void
    {
        $manager = new AtomsManager($this->app->make(AtomsClient::class));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('TicketIssuer');

        $manager->ticket(GameRoom::class, 'g-1');
    }

    public function testWsUrlIsBuiltFromTheConfiguredEndpoint(): void
    {
        config(['atoms.endpoint' => 'https://atoms.example.workers.dev']);
        $this->app->forgetInstance(\Atoms\Client\AtomsConfig::class);
        $this->app->forgetInstance(AtomsClient::class);
        $this->app->forgetInstance(AtomsManager::class);

        self::assertSame(
            'wss://atoms.example.workers.dev/ws/GameRoom/g-1?channels=lobby',
            Atoms::wsUrl(GameRoom::class, 'g-1', ['channels' => 'lobby']),
        );
    }

    public function testWsUrlIsRealEvenUnderFake(): void
    {
        config(['atoms.endpoint' => 'https://atoms.example.workers.dev']);
        $this->app->forgetInstance(\Atoms\Client\AtomsConfig::class);
        $this->app->forgetInstance(AtomsClient::class);
        $this->app->forgetInstance(AtomsManager::class);

        Atoms::fake();

        // String assembly over configuration: there is no request to intercept,
        // and a test asserting the URL a view renders wants the real one.
        self::assertSame(
            'wss://atoms.example.workers.dev/ws/GameRoom/g-1',
            Atoms::wsUrl(GameRoom::class, 'g-1'),
        );
    }
}
