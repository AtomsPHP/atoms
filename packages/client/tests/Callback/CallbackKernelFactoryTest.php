<?php

declare(strict_types=1);

namespace Atoms\Client\Tests\Callback;

use Atoms\Client\Callback\CallbackKernelFactory;
use Atoms\Client\Callback\InMemoryNonceStore;
use Atoms\Client\Callback\MethodsResolver;
use Atoms\Client\Tests\Fixtures\GameRoom;
use Atoms\Client\Tests\Fixtures\SendWelcomeJob;
use Atoms\Client\Tests\Support\RecordingQueueBridge;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class CallbackKernelFactoryTest extends TestCase
{
    private string $publicKey;

    private string $secretKey;

    protected function setUp(): void
    {
        $keypair = sodium_crypto_sign_keypair();
        $this->publicKey = sodium_crypto_sign_publickey($keypair);
        $this->secretKey = sodium_crypto_sign_secretkey($keypair);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function signedRequest(
        string $kind,
        array $payload,
        ?int $timestamp = null,
        ?string $nonce = null,
    ): ServerRequestInterface {
        $factory = new HttpFactory();
        $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
        $ts = (string) ($timestamp ?? time());
        $nonce ??= bin2hex(random_bytes(16));

        $message = "v1\n" . $ts . "\n" . $nonce . "\n" . $body;
        $signature = base64_encode(sodium_crypto_sign_detached($message, $this->secretKey));

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
        $kernel = CallbackKernelFactory::create(
            base64_encode($this->publicKey),
            $factory,
            $factory,
        );

        $request = $this->signedRequest('methods', [
            'atom' => ['type' => GameRoom::class, 'id' => 'g-1'],
            'method' => 'add',
            'args' => [2, 3],
        ]);

        $response = $kernel->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['result' => 5], $this->decode($response));
    }

    public function testDefaultQueueBridgeRejectsJobsWithE103(): void
    {
        $factory = new HttpFactory();
        $kernel = CallbackKernelFactory::create(
            base64_encode($this->publicKey),
            $factory,
            $factory,
        );

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
            base64_encode($this->publicKey),
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
            base64_encode($this->publicKey),
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
            base64_encode($this->publicKey),
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
