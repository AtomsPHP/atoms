<?php

declare(strict_types=1);

namespace Atoms\Laravel\Tests;

use Atoms\Client\AtomsClient;
use Atoms\Client\AtomsConfig;
use Atoms\Client\Exception\InvalidTicketClaims;
use Atoms\Client\Exception\PlatformUnavailable;
use Atoms\Client\Tickets\Ticket;
use Atoms\Laravel\AtomsManager;
use Atoms\Laravel\Facades\Atoms;
use Atoms\Laravel\Tests\Fixtures\GameRoom;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

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

    public function testDestroyDelegatesToAtomsClientAndSendsSingleDeleteOnLostResponse(): void
    {
        $http = new class implements ClientInterface {
            /** @var list<RequestInterface> */
            public array $requests = [];

            /** @var list<ResponseInterface|NetworkExceptionInterface> */
            public array $queue = [];

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->requests[] = $request;
                $next = array_shift($this->queue);
                if ($next instanceof NetworkExceptionInterface) {
                    throw $next;
                }
                if (!$next instanceof ResponseInterface) {
                    throw new \RuntimeException('No queued response');
                }

                return $next;
            }
        };

        $factory = new HttpFactory();
        $client = new AtomsClient(
            new AtomsConfig('https://atoms.example.workers.dev', self::SHARED_SECRET, maxAttempts: 4),
            $http,
            $factory,
            $factory,
        );
        $this->app->instance(AtomsClient::class, $client);
        $this->app->forgetInstance(AtomsManager::class);

        $http->queue[] = new Response(200, ['Content-Type' => 'application/json'], '{"destroyed":true}');
        $http->queue[] = new Response(200, ['Content-Type' => 'application/json'], '{"destroyed":false}');

        self::assertTrue(Atoms::destroy(GameRoom::class, 'g-1'));
        self::assertFalse(Atoms::destroy('GameRoom', 'g-1'));
        self::assertCount(2, $http->requests);
        self::assertSame('DELETE', $http->requests[0]->getMethod());
        self::assertSame('https://atoms.example.workers.dev/atoms/GameRoom/g-1', (string) $http->requests[0]->getUri());

        // Lost response sends only one DELETE and reports outcome unknown.
        $http->requests = [];
        $http->queue[] = new class($factory->createRequest('DELETE', 'https://atoms.example.workers.dev/atoms/GameRoom/g-2')) extends \RuntimeException implements NetworkExceptionInterface {
            public function __construct(private readonly RequestInterface $req)
            {
                parent::__construct('connection reset');
            }

            public function getRequest(): RequestInterface
            {
                return $this->req;
            }
        };
        $http->queue[] = new Response(200, ['Content-Type' => 'application/json'], '{"destroyed":true}');

        try {
            Atoms::destroy(GameRoom::class, 'g-2');
            self::fail('expected PlatformUnavailable');
        } catch (PlatformUnavailable $e) {
            self::assertStringContainsString('outcome unknown', $e->getMessage());
        }

        self::assertCount(1, $http->requests);
    }
}
