<?php

declare(strict_types=1);

namespace Atoms\Client\Tests\Tickets;

use Atoms\Client\AtomsConfig;
use Atoms\Client\Exception\AtomNotDeployed;
use Atoms\Client\Exception\TicketAcquisitionFailed;
use Atoms\Client\Tests\Support\FakeNetworkException;
use Atoms\Client\Tests\Support\FakePsr18Client;
use Atoms\Client\Tickets\Ticket;
use Atoms\Client\Tickets\TicketClient;
use Atoms\Errors\ErrorCode;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;

final class TicketClientTest extends TestCase
{
    /** The reference vector's secret (docs/shared-secret.md). */
    private const SECRET = 'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=';

    /** The bearer HKDF derives from it — asserted as a literal, per the vector. */
    private const BEARER = 'Bearer Dx6RY9LS43pOQhM4PMdaUWx3lk9mfyiiJZFfJtvl9E0=';

    private FakePsr18Client $http;

    /** @var list<int> milliseconds passed to the injected sleep */
    private array $sleeps;

    private function client(array $configOverrides = []): TicketClient
    {
        $this->http = new FakePsr18Client();
        $this->sleeps = [];

        $factory = new HttpFactory();
        $config = AtomsConfig::fromArray($configOverrides + [
            'endpoint' => 'https://atoms.example.workers.dev/',
            'sharedSecret' => self::SECRET,
            'maxAttempts' => 3,
            'backoffBaseMs' => 50,
            'backoffJitter' => false,
        ]);

        $seq = 0;

        return new TicketClient(
            $config,
            $this->http,
            $factory,
            $factory,
            function (int $ms): void {
                $this->sleeps[] = $ms;
            },
            function (int $n) use (&$seq): string {
                $seq++;

                return str_pad(pack('N', $seq), $n, "\x00");
            },
        );
    }

    public function testAcquireReturnsTicketAndSendsTheExpectedRequest(): void
    {
        $client = $this->client();
        $this->http->queueJson(200, [
            'ticket' => 'v1.payload.sig',
            'expires_at' => 1755200000000,
            'atom' => ['type' => 'Room', 'id' => 'g/1'],
        ]);

        $ticket = $client->acquire('Room', 'g/1');

        self::assertInstanceOf(Ticket::class, $ticket);
        self::assertSame('v1.payload.sig', $ticket->ticket);
        self::assertSame(1755200000000, $ticket->expiresAtMs);

        $req = $this->http->lastRequest();
        self::assertSame('POST', $req->getMethod());
        self::assertSame('https://atoms.example.workers.dev/tickets/Room/g%2F1', (string) $req->getUri());
        self::assertSame(self::BEARER, $req->getHeaderLine('Authorization'));
        self::assertSame('application/json', $req->getHeaderLine('Accept'));
        self::assertMatchesRegularExpression('/^00-[0-9a-f]{32}-[0-9a-f]{16}-01$/', $req->getHeaderLine('traceparent'));
        self::assertSame('', $req->getHeaderLine('Content-Type'), 'no claims => no body, no Content-Type');
        self::assertSame('', (string) $req->getBody());
    }

    public function testClaimsAreSentAsAJsonBody(): void
    {
        $client = $this->client();
        $this->http->queueJson(200, ['ticket' => 'v1.p.s', 'expires_at' => 1]);

        $client->acquire('Room', 'g-1', ['client_id' => 'u1', 'mode' => 'player']);

        $req = $this->http->lastRequest();
        self::assertSame('application/json', $req->getHeaderLine('Content-Type'));
        self::assertSame('{"claims":{"client_id":"u1","mode":"player"}}', (string) $req->getBody());
    }

    /**
     * Minting carries the same derived bearer as an invocation, including when
     * claims turn the request into a JSON POST.
     */
    public function testTheDerivedBearerIsSentOnEveryMint(): void
    {
        $client = $this->client();
        $this->http
            ->queueJson(200, ['ticket' => 'v1.p.s', 'expires_at' => 1])
            ->queueJson(200, ['ticket' => 'v1.p.s', 'expires_at' => 2]);

        $client->acquire('Room', 'g-1');
        $client->acquire('Room', 'g-1', ['client_id' => 'u1']);

        foreach ($this->http->requests as $request) {
            self::assertSame(self::BEARER, $request->getHeaderLine('Authorization'));
        }

        self::assertSame(
            (new AtomsConfig('https://atoms.example.workers.dev', self::SECRET))->bearerToken(),
            substr(self::BEARER, strlen('Bearer ')),
        );
    }

    public function testAConfiguredPreviousSecretDoesNotChangeTheBearerSent(): void
    {
        $client = $this->client(['sharedSecretPrevious' => base64_encode(str_repeat("\x02", 32))]);
        $this->http->queueJson(200, ['ticket' => 'v1.p.s', 'expires_at' => 3]);

        $client->acquire('Room', 'g-1');

        self::assertSame(self::BEARER, $this->http->lastRequest()->getHeaderLine('Authorization'));
    }

    public function testNotSupportedMapsToTicketAcquisitionFailedWithE067AndNoRetry(): void
    {
        $client = $this->client();
        $this->http->queueJson(501, ['error' => ['code' => 'not_supported', 'message' => 'no ws handler', 'retryable' => false]]);

        try {
            $client->acquire('Vault', 'v-1');
            self::fail('expected TicketAcquisitionFailed');
        } catch (TicketAcquisitionFailed $e) {
            self::assertSame('Vault', $e->type);
            self::assertSame('v-1', $e->id);
            self::assertSame('not_supported', $e->platformCode);
            self::assertSame(501, $e->httpStatus);
            self::assertSame(ErrorCode::WsTicketAcquisitionFailed, $e->errorCode);
            self::assertStringContainsString('ATOMS-E067', $e->getMessage());
            self::assertStringContainsString('Fix:', $e->getMessage());
            self::assertStringContainsString('no ws handler', $e->getMessage());
        }

        self::assertCount(1, $this->http->requests, 'not retried');
        self::assertSame([], $this->sleeps);
    }

    public function testUnauthenticatedMapsToTicketAcquisitionFailedAndIsNotRetried(): void
    {
        $client = $this->client();
        $this->http->queueJson(401, ['error' => ['code' => 'unauthenticated', 'message' => 'missing or invalid bearer token', 'retryable' => false]]);

        try {
            $client->acquire('Room', 'g-1');
            self::fail('expected TicketAcquisitionFailed');
        } catch (TicketAcquisitionFailed $e) {
            self::assertSame('unauthenticated', $e->platformCode);
            self::assertStringContainsString('ATOMS-E067', $e->getMessage());
        }

        self::assertCount(1, $this->http->requests);
    }

    public function testUnknownAtomTypeMapsToAtomNotDeployed(): void
    {
        $client = $this->client();
        $this->http->queueJson(404, ['error' => ['code' => 'unknown_atom_type', 'message' => 'nope', 'retryable' => false]]);

        try {
            $client->acquire('Ghost', 'g-1');
            self::fail('expected AtomNotDeployed');
        } catch (AtomNotDeployed $e) {
            self::assertSame('Ghost', $e->type);
            self::assertSame(ErrorCode::AtomTypeNotDeployed, $e->errorCode);
        }
    }

    public function testRetryableEnvelopeRetriesThenSucceeds(): void
    {
        $client = $this->client();
        $this->http
            ->queueJson(500, ['error' => ['code' => 'internal', 'message' => 'transient', 'retryable' => true]])
            ->queueJson(200, ['ticket' => 'v1.p.s', 'expires_at' => 3]);

        $ticket = $client->acquire('Room', 'g-1');

        self::assertSame('v1.p.s', $ticket->ticket);
        self::assertCount(2, $this->http->requests);
        self::assertSame([50], $this->sleeps, 'one exponential backoff before the retry');
    }

    public function testNetworkExceptionRetriesThenSucceeds(): void
    {
        $client = $this->client();
        $probe = new Request('POST', 'https://atoms.example.workers.dev/tickets/Room/g-1');
        $this->http
            ->queueThrowable(new FakeNetworkException($probe))
            ->queueJson(200, ['ticket' => 'v1.p.s', 'expires_at' => 4]);

        $ticket = $client->acquire('Room', 'g-1');

        self::assertSame('v1.p.s', $ticket->ticket);
        self::assertCount(2, $this->http->requests);
        self::assertSame([50], $this->sleeps);
    }

    public function testNetworkExceptionsExhaustMaxAttempts(): void
    {
        $client = $this->client();
        $probe = new Request('POST', 'https://atoms.example.workers.dev/tickets/Room/g-1');
        $this->http
            ->queueThrowable(new FakeNetworkException($probe))
            ->queueThrowable(new FakeNetworkException($probe))
            ->queueThrowable(new FakeNetworkException($probe));

        try {
            $client->acquire('Room', 'g-1');
            self::fail('expected TicketAcquisitionFailed');
        } catch (TicketAcquisitionFailed $e) {
            self::assertSame('transport', $e->platformCode);
            self::assertSame(0, $e->httpStatus);
            self::assertStringContainsString('ATOMS-E067', $e->getMessage());
        }

        self::assertCount(3, $this->http->requests, 'maxAttempts requests were made');
        self::assertSame([50, 100], $this->sleeps, 'exponential backoff between attempts');
    }

    public function testASuccessBodyMissingTheTicketKeyThrows(): void
    {
        $client = $this->client();
        $this->http->queueJson(200, ['expires_at' => 5]);

        try {
            $client->acquire('Room', 'g-1');
            self::fail('expected TicketAcquisitionFailed');
        } catch (TicketAcquisitionFailed $e) {
            self::assertStringContainsString('malformed', $e->getMessage());
            self::assertSame(200, $e->httpStatus);
        }
    }
}
