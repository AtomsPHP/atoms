<?php

declare(strict_types=1);

namespace Atoms\Client\Tests\Callback;

use Atoms\AtomJob;
use Atoms\Client\Callback\CallbackKernel;
use Atoms\Client\Callback\Ed25519Verifier;
use Atoms\Client\Callback\InMemoryNonceStore;
use Atoms\Client\Callback\MethodsResolver;
use Atoms\Client\Callback\QueueBridge;
use Atoms\Client\Tests\Fixtures\GameRoom;
use Atoms\Client\Tests\Fixtures\NotAJob;
use Atoms\Client\Tests\Fixtures\SendWelcomeJob;
use Atoms\Client\Tests\Support\RecordingQueueBridge;
use Atoms\Errors\AtomsError;
use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

final class CallbackKernelTest extends TestCase
{
    private string $publicKey;

    private string $secretKey;

    private RecordingQueueBridge $bridge;

    private InMemoryNonceStore $nonces;

    protected function setUp(): void
    {
        $keypair = sodium_crypto_sign_keypair();
        $this->publicKey = sodium_crypto_sign_publickey($keypair);
        $this->secretKey = sodium_crypto_sign_secretkey($keypair);
        $this->bridge = new RecordingQueueBridge();
        $this->nonces = new InMemoryNonceStore();
    }

    private function kernel(): CallbackKernel
    {
        $factory = new HttpFactory();
        $resolver = (new MethodsResolver())->registerTypeMap(['GameRoom' => GameRoom::class]);

        return new CallbackKernel(
            new Ed25519Verifier(base64_encode($this->publicKey)),
            $this->nonces,
            $resolver,
            $this->bridge,
            $factory,
            $factory,
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function signedRequest(
        string $kind,
        array $payload,
        ?int $timestamp = null,
        ?string $nonce = null,
        ?string $signatureOverride = null,
    ): ServerRequestInterface {
        $factory = new HttpFactory();
        $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
        $ts = (string) ($timestamp ?? time());
        $nonce ??= bin2hex(random_bytes(16));

        $message = "v1\n" . $ts . "\n" . $nonce . "\n" . $body;
        $signature = $signatureOverride ?? base64_encode(sodium_crypto_sign_detached($message, $this->secretKey));

        return $factory->createServerRequest('POST', 'https://app.test/_atoms/callback')
            ->withHeader('X-Atoms-Kind', $kind)
            ->withHeader('X-Atoms-Timestamp', $ts)
            ->withHeader('X-Atoms-Nonce', $nonce)
            ->withHeader('X-Atoms-Signature', $signature)
            ->withBody($factory->createStream($body));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    public function testValidMethodsCallExecutesAndReturnsResult(): void
    {
        $request = $this->signedRequest('methods', [
            'atom' => ['type' => 'GameRoom', 'id' => 'g-1'],
            'method' => 'add',
            'args' => [2, 3],
        ]);

        $response = $this->kernel()->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['result' => 5], $this->decode($response));
    }

    public function testMethodsCallDenormalizesPayloadArgument(): void
    {
        $request = $this->signedRequest('methods', [
            'atom' => ['type' => 'GameRoom', 'id' => 'g-1'],
            'method' => 'describe',
            'args' => [['name' => 'ada', 'score' => 9]],
        ]);

        $response = $this->kernel()->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['result' => 'ada:9'], $this->decode($response));
    }

    public function testBadSignatureIsRejected401E064(): void
    {
        $request = $this->signedRequest('methods', [
            'atom' => ['type' => 'GameRoom', 'id' => 'g-1'],
            'method' => 'add',
            'args' => [2, 3],
        ], signatureOverride: base64_encode(str_repeat("\x01", SODIUM_CRYPTO_SIGN_BYTES)));

        $response = $this->kernel()->handle($request);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('ATOMS-E064', $this->decode($response)['error']['code']);
    }

    public function testStaleTimestampIsRejected401E065(): void
    {
        $request = $this->signedRequest('methods', [
            'atom' => ['type' => 'GameRoom', 'id' => 'g-1'],
            'method' => 'add',
            'args' => [2, 3],
        ], timestamp: time() - 3600);

        $response = $this->kernel()->handle($request);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('ATOMS-E065', $this->decode($response)['error']['code']);
    }

    public function testNonceReplayIsRejected401E065(): void
    {
        $nonce = bin2hex(random_bytes(16));
        $payload = [
            'atom' => ['type' => 'GameRoom', 'id' => 'g-1'],
            'method' => 'add',
            'args' => [2, 3],
        ];

        $kernel = $this->kernel();

        $first = $kernel->handle($this->signedRequest('methods', $payload, nonce: $nonce));
        self::assertSame(200, $first->getStatusCode());

        $replay = $kernel->handle($this->signedRequest('methods', $payload, nonce: $nonce));
        self::assertSame(401, $replay->getStatusCode());
        self::assertSame('ATOMS-E065', $this->decode($replay)['error']['code']);
    }

    public function testJobKindReconstructsAndEnqueues(): void
    {
        $request = $this->signedRequest('job', [
            'job' => SendWelcomeJob::class,
            'args' => ['playerId' => 'p-9', 'roomSize' => 4, 'vip' => true],
        ]);

        $response = $this->kernel()->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['queued' => true], $this->decode($response));

        self::assertCount(1, $this->bridge->jobs);
        $job = $this->bridge->jobs[0];
        self::assertInstanceOf(SendWelcomeJob::class, $job);
        self::assertSame('p-9', $job->playerId);
        self::assertSame(4, $job->roomSize);
        self::assertTrue($job->vip);
    }

    public function testJobKindUsesConstructorDefaultWhenArgOmitted(): void
    {
        $request = $this->signedRequest('job', [
            'job' => SendWelcomeJob::class,
            'args' => ['playerId' => 'p-1', 'roomSize' => 2],
        ]);

        $this->kernel()->handle($request);

        self::assertInstanceOf(SendWelcomeJob::class, $this->bridge->jobs[0]);
        self::assertFalse($this->bridge->jobs[0]->vip);
    }

    public function testNonAtomJobRejected422E033(): void
    {
        $request = $this->signedRequest('job', [
            'job' => NotAJob::class,
            'args' => ['whatever' => 'x'],
        ]);

        $response = $this->kernel()->handle($request);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('ATOMS-E033', $this->decode($response)['error']['code']);
        self::assertCount(0, $this->bridge->jobs);
    }

    public function testUnresolvableMethodsClassRejected422E066(): void
    {
        $request = $this->signedRequest('methods', [
            'atom' => ['type' => 'Ghost', 'id' => 'g-1'],
            'method' => 'add',
            'args' => [],
        ]);

        $response = $this->kernel()->handle($request);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('ATOMS-E066', $this->decode($response)['error']['code']);
    }

    public function testUnknownMethodRejected422E030(): void
    {
        $request = $this->signedRequest('methods', [
            'atom' => ['type' => 'GameRoom', 'id' => 'g-1'],
            'method' => 'noSuchMethod',
            'args' => [],
        ]);

        $response = $this->kernel()->handle($request);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('ATOMS-E030', $this->decode($response)['error']['code']);
    }

    public function testCustomerExceptionBecomes500Sanitized(): void
    {
        $request = $this->signedRequest('methods', [
            'atom' => ['type' => 'GameRoom', 'id' => 'g-1'],
            'method' => 'boom',
            'args' => [],
        ]);

        $response = $this->kernel()->handle($request);

        self::assertSame(500, $response->getStatusCode());
        $body = $this->decode($response);
        self::assertSame('internal', $body['error']['code']);
        self::assertStringContainsString('RuntimeException', $body['error']['message']);
        self::assertStringNotContainsString("\n", $body['error']['message']);
    }

    private function kernelWithBridgeAndLogger(QueueBridge $bridge, ?LoggerInterface $logger): CallbackKernel
    {
        $factory = new HttpFactory();
        $resolver = (new MethodsResolver())->registerTypeMap(['GameRoom' => GameRoom::class]);

        return new CallbackKernel(
            new Ed25519Verifier(base64_encode($this->publicKey)),
            $this->nonces,
            $resolver,
            $bridge,
            $factory,
            $factory,
            logger: $logger,
        );
    }

    public function testJobEnqueueThrowingAtomsErrorReturns500WithCatalogCode(): void
    {
        $bridge = new class implements QueueBridge {
            public function enqueue(AtomJob $job): void
            {
                throw new AtomsError(
                    ErrorCode::NoQueueBridgeConfigured,
                    ErrorCatalog::format(ErrorCode::NoQueueBridgeConfigured, ['job' => $job::class]),
                );
            }
        };
        $logger = new RecordingLogger();
        $kernel = $this->kernelWithBridgeAndLogger($bridge, $logger);

        $request = $this->signedRequest('job', [
            'job' => SendWelcomeJob::class,
            'args' => ['playerId' => 'p-1', 'roomSize' => 2],
        ]);

        $response = $kernel->handle($request);

        self::assertSame(500, $response->getStatusCode());
        $body = $this->decode($response);
        self::assertSame('ATOMS-E103', $body['error']['code']);
        self::assertStringContainsString('ATOMS-E103', $body['error']['message']);
        self::assertNotEmpty($logger->records);
        self::assertSame('error', $logger->records[0]['level']);
    }

    public function testJobEnqueueThrowingGenericExceptionReturns500Internal(): void
    {
        $bridge = new class implements QueueBridge {
            public function enqueue(AtomJob $job): void
            {
                throw new \RuntimeException('queue connection refused');
            }
        };
        $logger = new RecordingLogger();
        $kernel = $this->kernelWithBridgeAndLogger($bridge, $logger);

        $request = $this->signedRequest('job', [
            'job' => SendWelcomeJob::class,
            'args' => ['playerId' => 'p-1', 'roomSize' => 2],
        ]);

        $response = $kernel->handle($request);

        self::assertSame(500, $response->getStatusCode());
        $body = $this->decode($response);
        self::assertSame('internal', $body['error']['code']);
        self::assertSame('Job could not be enqueued.', $body['error']['message']);
        self::assertStringNotContainsString('RuntimeException', $body['error']['message']);
        self::assertStringNotContainsString('queue connection refused', $body['error']['message']);

        self::assertNotEmpty($logger->records);
        self::assertSame('error', $logger->records[0]['level']);
        self::assertSame('queue connection refused', $logger->records[0]['context']['message']);
        self::assertSame(\RuntimeException::class, $logger->records[0]['context']['exception']);
        self::assertSame(SendWelcomeJob::class, $logger->records[0]['context']['job']);
    }
}
