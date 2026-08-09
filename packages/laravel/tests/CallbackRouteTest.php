<?php

declare(strict_types=1);

namespace Atoms\Laravel\Tests;

use Atoms\Laravel\Tests\Fixtures\GameRoom;

/**
 * End-to-end coverage of the auto-registered callback route: Illuminate
 * Request -> PSR-7 -> CallbackKernel -> PSR-7 Response -> Illuminate Response,
 * signed exactly per docs/conventions.md "Callback signing".
 */
final class CallbackRouteTest extends TestCase
{
    private string $publicKey;

    private string $secretKey;

    protected function setUp(): void
    {
        parent::setUp();

        $keypair = sodium_crypto_sign_keypair();
        $this->publicKey = sodium_crypto_sign_publickey($keypair);
        $this->secretKey = sodium_crypto_sign_secretkey($keypair);

        config(['atoms.platform_public_key' => base64_encode($this->publicKey)]);
    }

    public function testValidMethodsCallExecutesFixtureMethodsClassAndReturns200(): void
    {
        [$server, $body] = $this->signedRequest('methods', [
            'atom' => ['type' => GameRoom::class, 'id' => 'g-1'],
            'method' => 'add',
            'args' => [2, 3],
        ]);

        $response = $this->call('POST', '/atoms/callback', [], [], [], $server, $body);

        $response->assertStatus(200);
        $response->assertJson(['result' => 5]);
    }

    public function testBadSignatureIsRejectedWith401(): void
    {
        [$server, $body] = $this->signedRequest(
            'methods',
            [
                'atom' => ['type' => GameRoom::class, 'id' => 'g-1'],
                'method' => 'add',
                'args' => [2, 3],
            ],
            signatureOverride: base64_encode(str_repeat("\x01", SODIUM_CRYPTO_SIGN_BYTES)),
        );

        $response = $this->call('POST', '/atoms/callback', [], [], [], $server, $body);

        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 'ATOMS-E064');
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{0: array<string, string>, 1: string}
     */
    private function signedRequest(
        string $kind,
        array $payload,
        ?string $signatureOverride = null,
        ?int $timestamp = null,
        ?string $nonce = null,
    ): array {
        $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
        $ts = (string) ($timestamp ?? time());
        $nonce ??= bin2hex(random_bytes(16));

        $message = "v1\n" . $ts . "\n" . $nonce . "\n" . $body;
        $signature = $signatureOverride ?? base64_encode(sodium_crypto_sign_detached($message, $this->secretKey));

        $server = $this->transformHeadersToServerVars([
            'X-Atoms-Kind' => $kind,
            'X-Atoms-Timestamp' => $ts,
            'X-Atoms-Nonce' => $nonce,
            'X-Atoms-Signature' => $signature,
            'Content-Type' => 'application/json',
        ]);

        return [$server, $body];
    }
}
