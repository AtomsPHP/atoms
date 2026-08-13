<?php

declare(strict_types=1);

namespace Atoms\Laravel\Tests;

use Atoms\Laravel\Tests\Fixtures\GameRoom;
use Atoms\Laravel\Tests\Support\RecordingLogger;
use Psr\Log\LoggerInterface;

/**
 * Covers T7's other supply-contract gap: AtomsServiceProvider must pass the
 * app's bound Psr\Log\LoggerInterface into CallbackKernel (arg 10), so a
 * Methods invocation that throws gets logged through the host app's own
 * logging stack rather than silently swallowed. Reuses CallbackRouteTest's
 * signing helper.
 */
final class CallbackLoggerTest extends TestCase
{
    private string $publicKey;

    private string $secretKey;

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $keypair = sodium_crypto_sign_keypair();
        $this->publicKey = sodium_crypto_sign_publickey($keypair);
        $this->secretKey = sodium_crypto_sign_secretkey($keypair);

        config(['atoms.platform_public_key' => base64_encode($this->publicKey)]);

        // Bind BEFORE anything resolves CallbackKernel (it's a lazily-built
        // singleton, first constructed when the route dispatches the request
        // below) so the provider's closure sees the binding and wires it in.
        $this->logger = new RecordingLogger();
        $this->app->instance(LoggerInterface::class, $this->logger);
    }

    public function testMethodsInvocationThrowingLogsAnErrorRecord(): void
    {
        [$server, $body] = $this->signedRequest([
            'atom' => ['type' => GameRoom::class, 'id' => 'g-1'],
            'method' => 'explode',
            'args' => [],
        ]);

        $response = $this->call('POST', '/atoms/callback', [], [], [], $server, $body);

        $response->assertStatus(500);

        $errorRecords = array_values(array_filter(
            $this->logger->records,
            static fn (array $record): bool => $record['level'] === 'error',
        ));

        self::assertNotEmpty($errorRecords);
        self::assertSame('Callback Methods invocation threw', $errorRecords[0]['message']);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{0: array<string, string>, 1: string}
     */
    private function signedRequest(array $payload): array
    {
        $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
        $ts = (string) time();
        $nonce = bin2hex(random_bytes(16));

        $message = "v1\n" . $ts . "\n" . $nonce . "\n" . $body;
        $signature = base64_encode(sodium_crypto_sign_detached($message, $this->secretKey));

        $server = $this->transformHeadersToServerVars([
            'X-Atoms-Kind' => 'methods',
            'X-Atoms-Timestamp' => $ts,
            'X-Atoms-Nonce' => $nonce,
            'X-Atoms-Signature' => $signature,
            'Content-Type' => 'application/json',
        ]);

        return [$server, $body];
    }
}
