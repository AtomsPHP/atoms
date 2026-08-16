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
    /** A second valid secret: 32 bytes of 0x02. */
    private const OTHER_SECRET = 'AgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgI=';

    public function testValidMethodsCallExecutesFixtureMethodsClassAndReturns200(): void
    {
        [$server, $body] = $this->signedCallback('methods', [
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
        [$server, $body] = $this->signedCallback(
            'methods',
            [
                'atom' => ['type' => GameRoom::class, 'id' => 'g-1'],
                'method' => 'add',
                'args' => [2, 3],
            ],
            signatureOverride: base64_encode(str_repeat("\x01", 32)),
        );

        $response = $this->call('POST', '/atoms/callback', [], [], [], $server, $body);

        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 'ATOMS-E064');
    }

    public function testASignatureFromAnotherSecretIsRejectedWith401(): void
    {
        [$server, $body] = $this->signedCallback('methods', [
            'atom' => ['type' => GameRoom::class, 'id' => 'g-1'],
            'method' => 'add',
            'args' => [2, 3],
        ], secret: self::OTHER_SECRET);

        $response = $this->call('POST', '/atoms/callback', [], [], [], $server, $body);

        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 'ATOMS-E064');
    }

    /**
     * Rotation: with the overlap configured, a callback signed under either
     * secret verifies.
     */
    public function testPreviousSecretIsAcceptedWhileTheOverlapIsConfigured(): void
    {
        config([
            'atoms.shared_secret' => self::OTHER_SECRET,
            'atoms.shared_secret_previous' => self::SHARED_SECRET,
        ]);

        $payload = [
            'atom' => ['type' => GameRoom::class, 'id' => 'g-1'],
            'method' => 'add',
            'args' => [2, 3],
        ];

        [$currentServer, $currentBody] = $this->signedCallback('methods', $payload, secret: self::OTHER_SECRET);
        $this->call('POST', '/atoms/callback', [], [], [], $currentServer, $currentBody)->assertStatus(200);

        [$previousServer, $previousBody] = $this->signedCallback('methods', $payload, secret: self::SHARED_SECRET);
        $this->call('POST', '/atoms/callback', [], [], [], $previousServer, $previousBody)->assertStatus(200);
    }
}
