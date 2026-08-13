<?php

declare(strict_types=1);

namespace Atoms\Laravel\Tests;

/**
 * Exercises config/atoms.php's ATOMS_CALLBACK_PATH env() call directly: a
 * real environment variable, set before the app boots, must relocate the
 * auto-registered callback route — as opposed to CallbackRouteTest's
 * coverage of the default path. Mirrors ConfigEnvOverrideTest's pattern of
 * putenv() in setUp()/tearDown() around parent::setUp().
 */
final class CallbackPathEnvOverrideTest extends TestCase
{
    private string $publicKey;

    private string $secretKey;

    protected function setUp(): void
    {
        putenv('ATOMS_CALLBACK_PATH=/hooks/atoms');

        parent::setUp();

        $keypair = sodium_crypto_sign_keypair();
        $this->publicKey = sodium_crypto_sign_publickey($keypair);
        $this->secretKey = sodium_crypto_sign_secretkey($keypair);

        config(['atoms.platform_public_key' => base64_encode($this->publicKey)]);
    }

    protected function tearDown(): void
    {
        putenv('ATOMS_CALLBACK_PATH');

        parent::tearDown();
    }

    public function testEnvVariableRelocatesTheNamedRoute(): void
    {
        self::assertSame('/hooks/atoms', config('atoms.callback.path'));
        self::assertTrue($this->app['router']->has('atoms.callback'));
        self::assertStringEndsWith('/hooks/atoms', route('atoms.callback'));
    }

    public function testRelocatedPathReachesTheKernel(): void
    {
        [$server, $body] = $this->signedRequest([
            'atom' => ['type' => \Atoms\Laravel\Tests\Fixtures\GameRoom::class, 'id' => 'g-1'],
            'method' => 'add',
            'args' => [2, 3],
        ]);

        $response = $this->call('POST', '/hooks/atoms', [], [], [], $server, $body);

        $response->assertStatus(200);
        $response->assertJson(['result' => 5]);
    }

    public function testDefaultPathIsNoLongerRegisteredWhenRelocated(): void
    {
        [$server, $body] = $this->signedRequest([
            'atom' => ['type' => \Atoms\Laravel\Tests\Fixtures\GameRoom::class, 'id' => 'g-1'],
            'method' => 'add',
            'args' => [2, 3],
        ]);

        $response = $this->call('POST', '/atoms/callback', [], [], [], $server, $body);

        $response->assertStatus(404);
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
