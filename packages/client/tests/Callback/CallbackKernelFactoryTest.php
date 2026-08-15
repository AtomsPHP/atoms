<?php

declare(strict_types=1);

namespace Atoms\Client\Tests\Callback;

use Atoms\Client\Callback\CallbackKernelFactory;
use Atoms\Client\Callback\InMemoryNonceStore;
use Atoms\Client\Callback\MethodsResolver;
use Atoms\Client\Crypto\KeyDerivation;
use Atoms\Client\Tests\Fixtures\GameRoom;
use Atoms\Client\Tests\Fixtures\SendWelcomeJob;
use Atoms\Client\Tests\Support\RecordingQueueBridge;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class CallbackKernelFactoryTest extends TestCase
{
    private const SECRET = 'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=';

    private const PREVIOUS_SECRET = 'AgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgI=';

    /**
     * @param array<string, mixed> $payload
     */
    private function signedRequest(
        string $kind,
        array $payload,
        ?int $timestamp = null,
        ?string $nonce = null,
        string $secret = self::SECRET,
    ): ServerRequestInterface {
        $factory = new HttpFactory();
        $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
        $ts = (string) ($timestamp ?? time());
        $nonce ??= bin2hex(random_bytes(16));

        $message = "v1\n" . $ts . "\n" . $nonce . "\n" . $body;
        $signature = base64_encode(hash_hmac('sha256', $message, KeyDerivation::callbackKey($secret), true));

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

    public function testDefaultsVerifyASignedMethodsRequestEndToEnd(): void
    {
        $factory = new HttpFactory();
        $kernel = CallbackKernelFactory::create(self::SECRET, $factory, $factory);

        $request = $this->signedRequest('methods', [
            'atom' => ['type' => GameRoom::class, 'id' => 'g-1'],
            'method' => 'add',
            'args' => [2, 3],
        ]);

        $response = $kernel->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['result' => 5], $this->decode($response));
    }

    public function testASignatureFromAnotherSecretIsRejected(): void
    {
        $factory = new HttpFactory();
        $kernel = CallbackKernelFactory::create(self::SECRET, $factory, $factory);

        $request = $this->signedRequest('methods', [
            'atom' => ['type' => GameRoom::class, 'id' => 'g-1'],
            'method' => 'add',
            'args' => [2, 3],
        ], secret: self::PREVIOUS_SECRET);

        $response = $kernel->handle($request);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('ATOMS-E064', $this->decode($response)['error']['code']);
    }

    public function testThePreviousSecretIsAcceptedWhenTheOverlapIsConfigured(): void
    {
        $factory = new HttpFactory();
        $kernel = CallbackKernelFactory::create(
            self::SECRET,
            $factory,
            $factory,
            sharedSecretPrevious: self::PREVIOUS_SECRET,
        );

        $payload = [
            'atom' => ['type' => GameRoom::class, 'id' => 'g-1'],
            'method' => 'add',
            'args' => [2, 3],
        ];

        self::assertSame(200, $kernel->handle($this->signedRequest('methods', $payload))->getStatusCode());
        self::assertSame(
            200,
            $kernel->handle($this->signedRequest('methods', $payload, secret: self::PREVIOUS_SECRET))->getStatusCode(),
        );
    }

    public function testAMalformedSecretIsRejectedWithE105(): void
    {
        $factory = new HttpFactory();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/ATOMS-E105/');

        CallbackKernelFactory::create('not-a-secret', $factory, $factory);
    }

    public function testDefaultQueueBridgeRejectsJobsWithE103(): void
    {
        $factory = new HttpFactory();
        $kernel = CallbackKernelFactory::create(self::SECRET, $factory, $factory);

        $request = $this->signedRequest('job', [
            'job' => SendWelcomeJob::class,
            'args' => ['playerId' => 'p-1', 'roomSize' => 2],
        ]);

        $response = $kernel->handle($request);

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('ATOMS-E103', $this->decode($response)['error']['code']);
    }

    public function testCustomQueueBridgeIsHonored(): void
    {
        $factory = new HttpFactory();
        $bridge = new RecordingQueueBridge();
        $kernel = CallbackKernelFactory::create(
            self::SECRET,
            $factory,
            $factory,
            queueBridge: $bridge,
        );

        $request = $this->signedRequest('job', [
            'job' => SendWelcomeJob::class,
            'args' => ['playerId' => 'p-1', 'roomSize' => 2],
        ]);

        $response = $kernel->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $bridge->jobs);
        self::assertInstanceOf(SendWelcomeJob::class, $bridge->jobs[0]);
    }

    public function testCustomResolverIsHonored(): void
    {
        $factory = new HttpFactory();
        $resolver = (new MethodsResolver())->registerTypeMap(['Room' => GameRoom::class]);
        $kernel = CallbackKernelFactory::create(
            self::SECRET,
            $factory,
            $factory,
            resolver: $resolver,
        );

        $request = $this->signedRequest('methods', [
            'atom' => ['type' => 'Room', 'id' => 'g-1'],
            'method' => 'add',
            'args' => [2, 3],
        ]);

        $response = $kernel->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['result' => 5], $this->decode($response));
    }

    public function testCustomNonceStoreIsHonored(): void
    {
        $factory = new HttpFactory();
        $nonceStore = new InMemoryNonceStore();
        $kernel = CallbackKernelFactory::create(
            self::SECRET,
            $factory,
            $factory,
            nonceStore: $nonceStore,
        );

        $payload = [
            'atom' => ['type' => GameRoom::class, 'id' => 'g-1'],
            'method' => 'add',
            'args' => [2, 3],
        ];
        $nonce = bin2hex(random_bytes(16));

        $first = $kernel->handle($this->signedRequest('methods', $payload, nonce: $nonce));
        self::assertSame(200, $first->getStatusCode());

        // The nonce is already recorded directly against the injected store,
        // proving the kernel is consulting the exact instance we passed in.
        self::assertTrue($nonceStore->seen($nonce));
    }
}
