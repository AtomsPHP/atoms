<?php

declare(strict_types=1);

namespace Atoms\Client\Tests;

use Atoms\Client\AtomsClient;
use Atoms\Client\AtomsConfig;
use Atoms\Client\Exception\AtomNotDeployed;
use Atoms\Client\Exception\AtomsRequestFailed;
use Atoms\Client\Exception\CapacityRefused;
use Atoms\Client\Exception\InvalidRequest;
use Atoms\Client\Exception\PlatformUnavailable;
use Atoms\Client\Exception\RemoteAtomException;
use Atoms\Client\Exception\TurnDeadlineExceeded;
use Atoms\Client\Tests\Fixtures\GameRoom;
use Atoms\Client\Tests\Fixtures\PlayerSnapshot;
use Atoms\Client\Tests\Support\FakeNetworkException;
use Atoms\Client\Tests\Support\FakePsr18Client;
use Atoms\Errors\ErrorCode;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\TestCase;

final class AtomsClientTest extends TestCase
{
    /** The reference vector's secret (docs/shared-secret.md). */
    private const SECRET = 'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=';

    /** The bearer HKDF derives from it — asserted as a literal, per the vector. */
    private const BEARER = 'Bearer Dx6RY9LS43pOQhM4PMdaUWx3lk9mfyiiJZFfJtvl9E0=';

    private FakePsr18Client $http;

    /** @var list<int> milliseconds passed to the injected sleep */
    private array $sleeps;

    private function client(array $configOverrides = []): AtomsClient
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

        return new AtomsClient(
            $config,
            $this->http,
            $factory,
            $factory,
            null,
            function (int $ms): void {
                $this->sleeps[] = $ms;
            },
            function (int $n) use (&$seq): string {
                $seq++;

                return str_pad(pack('N', $seq), $n, "\x00");
            },
        );
    }

    public function testInvokeUrlHeadersAndBody(): void
    {
        $client = $this->client();
        $this->http->queueJson(200, ['result' => 'pong', 'atom' => ['type' => 'GameRoom', 'id' => 'g-1'], 'version' => 'v3']);

        $result = $client->call('GameRoom', 'g-1', 'ping');

        self::assertSame('pong', $result);

        $req = $this->http->lastRequest();
        self::assertSame('POST', $req->getMethod());
        self::assertSame('https://atoms.example.workers.dev/invoke/GameRoom/g-1/ping', (string) $req->getUri());
        self::assertSame(self::BEARER, $req->getHeaderLine('Authorization'));
        self::assertSame('application/json', $req->getHeaderLine('Content-Type'));
        self::assertFalse($req->hasHeader('Idempotency-Key'), 'the runtime never read it, so it is not sent');
        self::assertMatchesRegularExpression('/^00-[0-9a-f]{32}-[0-9a-f]{16}-01$/', $req->getHeaderLine('traceparent'));
        self::assertSame('', $req->getHeaderLine('X-Atoms-Manifest-Hash'));
        self::assertSame('{"args":[]}', (string) $req->getBody());
    }

    public function testArgsAreNormalizedIntoBody(): void
    {
        $client = $this->client();
        $this->http->queueJson(200, ['result' => null]);

        $client->call('GameRoom', 'g-1', 'push', [['a', 1], 42]);

        self::assertSame('{"args":[["a",1],42]}', (string) $this->http->lastRequest()->getBody());
    }

    public function testRetryAfterHonoredOn429(): void
    {
        $client = $this->client();
        $this->http
            ->queueJson(429, ['error' => ['code' => 'rate_limited', 'message' => 'slow down', 'retryable' => true]], ['Retry-After' => '2'])
            ->queueJson(200, ['result' => 'ok']);

        self::assertSame('ok', $client->call('GameRoom', 'g-1', 'ping'));
        self::assertSame([2000], $this->sleeps, 'Retry-After of 2s becomes a 2000ms sleep');
    }

    public function testExponentialBackoffWithoutRetryAfter(): void
    {
        $client = $this->client();
        $this->http
            ->queueJson(503, ['error' => ['code' => 'capacity_refused', 'message' => 'busy']])
            ->queueJson(503, ['error' => ['code' => 'capacity_refused', 'message' => 'busy']])
            ->queueJson(200, ['result' => 'ok']);

        self::assertSame('ok', $client->call('GameRoom', 'g-1', 'ping'));
        self::assertSame([50, 100], $this->sleeps, 'base then base*2');
    }

    public function testNotFoundMapsToAtomNotDeployedWithNoRetry(): void
    {
        $client = $this->client();
        $this->http->queueJson(404, ['error' => ['code' => 'unknown_atom_type', 'message' => 'nope', 'retryable' => false]]);

        try {
            $client->call('GameRoom', 'g-1', 'ping');
            self::fail('expected AtomNotDeployed');
        } catch (AtomNotDeployed $e) {
            self::assertSame('GameRoom', $e->type);
            self::assertSame(ErrorCode::AtomTypeNotDeployed, $e->errorCode);
            self::assertStringContainsString('ATOMS-E060', $e->getMessage());
            self::assertStringContainsString('Fix:', $e->getMessage());
        }

        self::assertCount(1, $this->http->requests, 'not retried');
        self::assertSame([], $this->sleeps);
    }

    public function testTurnDeadlineIsNeverRetriedEvenThoughThePlatformFlagsItRetryable(): void
    {
        $client = $this->client();
        $this->http->queueJson(504, ['error' => ['code' => 'turn_deadline_exceeded', 'message' => 'slow', 'retryable' => true]]);

        $this->expectException(TurnDeadlineExceeded::class);
        try {
            $client->call('GameRoom', 'g-1', 'ping');
        } finally {
            self::assertCount(1, $this->http->requests);
            self::assertSame([], $this->sleeps);
        }
    }

    public function testCapacityRefusedCarriesRetryAfter(): void
    {
        $client = $this->client(['maxAttempts' => 1]);
        $this->http->queueJson(503, ['error' => ['code' => 'capacity_refused', 'message' => 'full']], ['Retry-After' => '5']);

        try {
            $client->call('GameRoom', 'g-1', 'ping');
            self::fail('expected CapacityRefused');
        } catch (CapacityRefused $e) {
            self::assertSame(5, $e->retryAfter);
            self::assertSame(ErrorCode::CapacityRefused, $e->errorCode);
            self::assertStringContainsString('ATOMS-E062', $e->getMessage());
        }
    }

    public function testMachineUnavailableMapsToPlatformUnavailable(): void
    {
        $client = $this->client(['maxAttempts' => 1]);
        $this->http->queueJson(502, ['error' => ['code' => 'machine_unavailable', 'message' => 'down']]);

        $this->expectException(PlatformUnavailable::class);
        $client->call('GameRoom', 'g-1', 'ping');
    }

    public function testInvalidRequestMapping(): void
    {
        $client = $this->client(['maxAttempts' => 1]);
        $this->http->queueJson(400, ['error' => ['code' => 'invalid_request', 'message' => 'bad path']]);

        try {
            $client->call('GameRoom', 'g-1', 'ping');
            self::fail('expected InvalidRequest');
        } catch (InvalidRequest $e) {
            self::assertSame('bad path', $e->getMessage());
            self::assertSame(400, $e->httpStatus);
        }
    }

    public function testUnmappedCodeFallsBackToAtomsRequestFailed(): void
    {
        $client = $this->client(['maxAttempts' => 1]);
        $this->http->queueJson(401, ['error' => ['code' => 'unauthenticated', 'message' => 'no key', 'retryable' => false]]);

        try {
            $client->call('GameRoom', 'g-1', 'ping');
            self::fail('expected AtomsRequestFailed');
        } catch (AtomsRequestFailed $e) {
            self::assertSame('unauthenticated', $e->platformCode);
            self::assertFalse($e->retryable);
        }
    }

    public function testRemoteAtomExceptionFromErrorFrame(): void
    {
        $client = $this->client(['maxAttempts' => 1]);
        $this->http->queueJson(500, ['error' => [
            'code' => 'internal',
            'message' => 'boom',
            'remote_class' => 'App\\Domain\\GameOverException',
            'remote_trace' => "#0 /app/Game.php(10)\0trailing",
        ]]);

        try {
            $client->call('GameRoom', 'g-1', 'endGame');
            self::fail('expected RemoteAtomException');
        } catch (RemoteAtomException $e) {
            self::assertSame('App\\Domain\\GameOverException', $e->originalClass);
            self::assertSame(ErrorCode::RemoteAtomException, $e->errorCode);
            self::assertStringContainsString('GameOverException', $e->getMessage());
            self::assertStringContainsString('GameRoom/g-1::endGame', $e->getMessage());
            self::assertNotNull($e->remoteTrace);
            self::assertStringNotContainsString("\0", $e->remoteTrace);
        }
    }

    /**
     * A 2xx body carrying an `error` object is a failure, not a result — the
     * Worker answers that way for a remote Atom exception. Retryability never
     * enters into it: the frame is mapped and thrown on the spot.
     */
    public function testATwoHundredCarryingAnErrorFrameIsMappedAndThrown(): void
    {
        $client = $this->client();
        $this->http->queueJson(200, ['error' => [
            'code' => 'atom_exception',
            'message' => 'boom',
            'remote_class' => 'App\\Domain\\GameOverException',
        ]]);

        try {
            $client->call('GameRoom', 'g-1', 'endGame');
            self::fail('expected RemoteAtomException');
        } catch (RemoteAtomException $e) {
            self::assertSame('App\\Domain\\GameOverException', $e->originalClass);
            self::assertSame(200, $e->httpStatus);
            self::assertNull($e->remoteTrace);
        }

        self::assertCount(1, $this->http->requests, 'not retried');
    }

    /**
     * The behaviour change from the single-parse refactor, pinned deliberately.
     *
     * A frame carrying BOTH `remote_class` and `turn_deadline_exceeded` maps to
     * a {@see RemoteAtomException}, so it is not retried. Retryability comes
     * from the mapped exception; it used to come from a second string match on
     * the raw envelope's `code`, which saw the deadline code and re-ran code
     * that had already thrown.
     */
    public function testARemoteExceptionCarryingATurnDeadlineCodeIsNotRetried(): void
    {
        $client = $this->client();
        $this->http
            ->queueJson(504, [
                'error' => [
                    'code' => 'turn_deadline_exceeded',
                    'message' => 'slow',
                    'retryable' => true,
                    'remote_class' => 'App\\Domain\\GameOverException',
                ],
            ])
            ->queueJson(200, ['result' => 'ok']);

        try {
            $client->call('GameRoom', 'g-1', 'endGame');
            self::fail('expected RemoteAtomException');
        } catch (RemoteAtomException $e) {
            self::assertSame('App\\Domain\\GameOverException', $e->originalClass);
        }

        self::assertCount(1, $this->http->requests, 'the customer exception is not retried');
        self::assertSame([], $this->sleeps);
    }

    /**
     * A non-2xx whose body carries no `error` object at all (a proxy's own HTML
     * error page, a truncated response) still has to produce an exception. It
     * takes the fallback arm with an empty code, and is not retryable.
     */
    public function testAFailureWithNoErrorFrameIsANonRetryableRequestFailure(): void
    {
        $client = $this->client();
        $this->http->queueJson(500, ['unrelated' => true]);

        try {
            $client->call('GameRoom', 'g-1', 'ping');
            self::fail('expected AtomsRequestFailed');
        } catch (AtomsRequestFailed $e) {
            self::assertSame('', $e->platformCode);
            self::assertFalse($e->retryable);
            self::assertSame(500, $e->httpStatus);
            self::assertSame('Request failed (unknown).', $e->getMessage());
        }

        self::assertCount(1, $this->http->requests, 'not retried');
        self::assertSame([], $this->sleeps);
    }

    /**
     * `directory_unavailable` was dropped from the retryable-code constant
     * because its explicit mapping already returns a retryable exception. This
     * pins that the drop was behaviour-preserving: no `retryable` flag on the
     * frame, so the retry can only be coming from {@see PlatformUnavailable}.
     */
    public function testACodeDroppedFromTheRetryableConstantStillRetriesViaItsMapping(): void
    {
        $client = $this->client();
        $this->http
            ->queueJson(502, ['error' => ['code' => 'directory_unavailable', 'message' => 'no route']])
            ->queueJson(200, ['result' => 'ok']);

        self::assertSame('ok', $client->call('GameRoom', 'g-1', 'ping'));
        self::assertCount(2, $this->http->requests);
    }

    /**
     * `deploy_in_progress` has no exception of its own, so the constant is the
     * only thing that can make it retryable.
     */
    public function testAnUnmappedRetryableCodeRetriesFromTheConstant(): void
    {
        $client = $this->client();
        $this->http
            ->queueJson(503, ['error' => ['code' => 'deploy_in_progress', 'message' => 'rolling out']])
            ->queueJson(200, ['result' => 'ok']);

        self::assertSame('ok', $client->call('GameRoom', 'g-1', 'ping'));
        self::assertCount(2, $this->http->requests);
    }

    /**
     * The constant's other entry. Against the current Worker `internal` always
     * arrives with `retryable: true`, so the constant only decides the flagless
     * case — which is exactly the case this pins.
     */
    public function testInternalRetriesFromTheConstantWithNoPlatformFlag(): void
    {
        $client = $this->client();
        $this->http
            ->queueJson(500, ['error' => ['code' => 'internal', 'message' => 'oops']])
            ->queueJson(200, ['result' => 'ok']);

        self::assertSame('ok', $client->call('GameRoom', 'g-1', 'ping'));
        self::assertCount(2, $this->http->requests);
    }

    /**
     * The other half of the 2xx path: an error frame on a 2xx with no
     * `remote_class` is still mapped and thrown on the spot — a 2xx frame
     * never reaches the retry predicate at all.
     */
    public function testATwoHundredErrorFrameIsNotRetried(): void
    {
        $client = $this->client();
        $this->http
            ->queueJson(200, ['error' => ['code' => 'turn_deadline_exceeded', 'message' => 'slow', 'retryable' => true]])
            ->queueJson(200, ['result' => 'ok']);

        try {
            $client->call('GameRoom', 'g-1', 'ping');
            self::fail('expected TurnDeadlineExceeded');
        } catch (TurnDeadlineExceeded $e) {
            self::assertSame(200, $e->httpStatus);
        }

        self::assertCount(1, $this->http->requests, 'a 2xx error frame is thrown, never retried');
        self::assertSame([], $this->sleeps);
    }

    /**
     * A code this client has never heard of is retried when — and only when —
     * the platform itself flags the frame retryable.
     */
    public function testAnUnknownCodeRetriesOnlyOnThePlatformsOwnRetryableFlag(): void
    {
        $client = $this->client();
        $this->http
            ->queueJson(503, ['error' => ['code' => 'shard_migrating', 'message' => 'moving', 'retryable' => true]])
            ->queueJson(200, ['result' => 'ok']);

        self::assertSame('ok', $client->call('GameRoom', 'g-1', 'ping'));
        self::assertCount(2, $this->http->requests);

        $client = $this->client();
        $this->http->queueJson(503, ['error' => ['code' => 'shard_migrating', 'message' => 'moving']]);

        try {
            $client->call('GameRoom', 'g-1', 'ping');
            self::fail('expected AtomsRequestFailed');
        } catch (AtomsRequestFailed $e) {
            self::assertSame('shard_migrating', $e->platformCode);
            self::assertFalse($e->retryable);
        }

        self::assertCount(1, $this->http->requests, 'an unflagged unknown code is not retried');
    }

    public function testTransportFailureExhaustsToPlatformUnavailable(): void
    {
        $client = $this->client();
        $factoryReq = (new HttpFactory())->createRequest('POST', 'https://x');
        $this->http
            ->queueThrowable(new FakeNetworkException($factoryReq))
            ->queueThrowable(new FakeNetworkException($factoryReq))
            ->queueThrowable(new FakeNetworkException($factoryReq));

        $this->expectException(PlatformUnavailable::class);
        try {
            $client->call('GameRoom', 'g-1', 'ping');
        } finally {
            self::assertCount(3, $this->http->requests);
            self::assertCount(2, $this->sleeps, 'slept between the 3 attempts');
        }
    }

    public function testDestroyReturnsFlag(): void
    {
        $client = $this->client();
        $this->http->queueJson(200, ['destroyed' => true]);

        self::assertTrue($client->destroy('GameRoom', 'g-1'));

        $req = $this->http->lastRequest();
        self::assertSame('DELETE', $req->getMethod());
        self::assertSame('https://atoms.example.workers.dev/atoms/GameRoom/g-1', (string) $req->getUri());
    }

    public function testDestroyIdempotentFalse(): void
    {
        $client = $this->client();
        $this->http->queueJson(200, ['destroyed' => false]);

        self::assertFalse($client->destroy('GameRoom', 'g-1'));
    }

    public function testProxyDenormalizesTypedReturn(): void
    {
        $client = $this->client();
        $this->http->queueJson(200, ['result' => ['name' => 'ada', 'score' => 7]]);

        $proxy = $client->get(GameRoom::class, 'g-1');
        /** @var PlayerSnapshot $snapshot */
        $snapshot = $proxy->snapshot('ada');

        self::assertInstanceOf(PlayerSnapshot::class, $snapshot);
        self::assertSame('ada', $snapshot->name);
        self::assertSame(7, $snapshot->score);

        // Wire type is the class basename.
        self::assertSame('https://atoms.example.workers.dev/invoke/GameRoom/g-1/snapshot', (string) $this->http->lastRequest()->getUri());
    }

    public function testProxyScalarReturnPassThrough(): void
    {
        $client = $this->client();
        $this->http->queueJson(200, ['result' => 'pong']);

        $proxy = $client->get(GameRoom::class, 'g-1');
        self::assertSame('pong', $proxy->ping());
    }

    /**
     * The bearer is derived from the shared secret, so every call carries the
     * same 44-character value the Worker derives from its own copy.
     */
    public function testEveryCallCarriesTheDerivedBearer(): void
    {
        $client = $this->client();
        $this->http
            ->queueJson(200, ['result' => 'pong'])
            ->queueJson(200, ['destroyed' => true]);

        $client->call('GameRoom', 'g-1', 'ping');
        $client->destroy('GameRoom', 'g-1');

        foreach ($this->http->requests as $request) {
            self::assertSame(self::BEARER, $request->getHeaderLine('Authorization'));
        }

        self::assertSame(
            (new AtomsConfig('https://atoms.example.workers.dev', self::SECRET))->bearerToken(),
            substr(self::BEARER, strlen('Bearer ')),
        );
    }

    /**
     * A rotation overlap widens which callbacks verify; the bearer on the way
     * out stays the current secret's.
     */
    public function testAConfiguredPreviousSecretDoesNotChangeTheBearerSent(): void
    {
        $client = $this->client(['sharedSecretPrevious' => base64_encode(str_repeat("\x02", 32))]);
        $this->http->queueJson(200, ['result' => 'pong']);

        $client->call('GameRoom', 'g-1', 'ping');

        self::assertSame(self::BEARER, $this->http->lastRequest()->getHeaderLine('Authorization'));
    }

    /**
     * A Worker whose own secret is missing or malformed answers `misconfigured`
     * on every route but /healthz. It is not retryable, and the message says
     * what to set.
     */
    public function testMisconfiguredWorkerMapsToAClearNonRetryableFailure(): void
    {
        $client = $this->client(['maxAttempts' => 3]);
        $this->http->queueJson(500, ['error' => [
            'code' => 'misconfigured',
            'message' => 'ATOMS_SHARED_SECRET is missing or is not base64 of 32 bytes',
            'retryable' => false,
        ]]);

        try {
            $client->call('GameRoom', 'g-1', 'ping');
            self::fail('expected AtomsRequestFailed');
        } catch (AtomsRequestFailed $e) {
            self::assertSame('misconfigured', $e->platformCode);
            self::assertFalse($e->retryable);
            self::assertSame(500, $e->httpStatus);
            self::assertStringContainsString('ATOMS_SHARED_SECRET', $e->getMessage());
            self::assertStringContainsString('wrangler secret put', $e->getMessage());
        }

        self::assertCount(1, $this->http->requests, 'not retried');
        self::assertSame([], $this->sleeps);
    }

    public function testAMalformedSecretThrowsAtConstruction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/ATOMS-E105/');

        $this->client(['sharedSecret' => 'nope']);
    }

    public function testManifestHashHeaderSentWhenLoaded(): void
    {
        $path = sys_get_temp_dir() . '/atoms-manifest-' . uniqid() . '.json';
        file_put_contents($path, (string) json_encode([
            'schema' => 1,
            'project' => ['name' => 'demo'],
            'atoms' => [],
            'content_hash' => 'deadbeef',
        ]));

        try {
            $client = $this->client(['manifestPath' => $path]);
            $this->http->queueJson(200, ['result' => 'pong']);
            $client->call('GameRoom', 'g-1', 'ping');

            self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $this->http->lastRequest()->getHeaderLine('X-Atoms-Manifest-Hash'));
        } finally {
            @unlink($path);
        }
    }

    public function testWsUrlDerivesFromTheEndpoint(): void
    {
        $client = $this->client();

        self::assertSame(
            'wss://atoms.example.workers.dev/ws/GameRoom/g-1',
            $client->wsUrl(GameRoom::class, 'g-1'),
        );
    }

    public function testWsUrlEncodesTheIdAndTheQuery(): void
    {
        $client = $this->client();

        // A list of channels becomes the comma-separated form the Worker
        // parses; the comma is percent-encoded and decodes back before the
        // Worker's channel-name check.
        self::assertSame(
            'wss://atoms.example.workers.dev/ws/GameRoom/room%20one?channels=lobby%2Cchat&mode=observe&ticket=v1.a.b',
            $client->wsUrl(GameRoom::class, 'room one', [
                'channels' => ['lobby', 'chat'],
                'mode' => 'observe',
                'ticket' => 'v1.a.b',
            ]),
        );
    }

    public function testWireTypeIsTheClassBasename(): void
    {
        self::assertSame('GameRoom', AtomsClient::wireType(GameRoom::class));
        self::assertSame('GameRoom', AtomsClient::wireType('GameRoom'));
    }

    public function testATurnDeadlineThroughTheProxyIsNotRetried(): void
    {
        $client = $this->client();
        $this->http->queueJson(500, ['error' => ['code' => 'turn_deadline_exceeded', 'message' => 'slow', 'retryable' => false]]);

        $this->expectException(TurnDeadlineExceeded::class);

        $client->get(GameRoom::class, 'g-1')->ping();
    }

    public function testReadingAPropertyThroughTheProxyIsALoudError(): void
    {
        $client = $this->client();

        // @return T makes `->id` statically legal; nothing was fetched, so the
        // honest answer is an exception rather than a warning and null.
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('cannot be read through a proxy');

        /** @phpstan-ignore-next-line deliberately reading a property off a proxy */
        $client->get(GameRoom::class, 'g-1')->id;
    }
}
