<?php

declare(strict_types=1);

namespace Atoms\Laravel\Tests;

use Atoms\Laravel\AtomsServiceProvider;
use Atoms\Laravel\Facades\Atoms;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * The reference vector's secret (docs/shared-secret.md): bytes 0x00-0x1f,
     * base64. Signing helpers derive the callback key from this same value.
     */
    public const SHARED_SECRET = 'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=';

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [AtomsServiceProvider::class];
    }

    /**
     * @return array<string, class-string>
     */
    protected function getPackageAliases($app): array
    {
        return ['Atoms' => Atoms::class];
    }

    /** Set false to boot an app with no shared secret configured at all. */
    protected bool $withSharedSecret = true;

    /**
     * Stand in for the operator's `ATOMS_SHARED_SECRET`, the way a configured
     * deployment holds one — unless a test drove the value itself (an env
     * override), in which case that value stands.
     */
    protected function defineEnvironment($app): void
    {
        if ($this->withSharedSecret && $app['config']->get('atoms.shared_secret') === null) {
            $app['config']->set('atoms.shared_secret', self::SHARED_SECRET);
        }
    }

    /**
     * The raw 32-byte HMAC key a Worker holding $secret signs callbacks with.
     */
    protected function callbackKey(string $secret = self::SHARED_SECRET): string
    {
        $decoded = base64_decode($secret, true);
        self::assertIsString($decoded);

        return hash_hkdf('sha256', $decoded, 32, 'atoms/callback/v1', '');
    }

    /**
     * Build the signed headers and body of a callback, exactly as the Worker
     * sends them: HMAC-SHA256 over `"v1\n{ts}\n{nonce}\n{body}"`, base64 in
     * `X-Atoms-Signature`.
     *
     * @param array<string, mixed> $payload
     * @return array{0: array<string, string>, 1: string}
     */
    protected function signedCallback(
        string $kind,
        array $payload,
        ?string $signatureOverride = null,
        ?int $timestamp = null,
        ?string $nonce = null,
        string $secret = self::SHARED_SECRET,
    ): array {
        $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
        $ts = (string) ($timestamp ?? time());
        $nonce ??= bin2hex(random_bytes(16));

        $message = "v1\n" . $ts . "\n" . $nonce . "\n" . $body;
        $signature = $signatureOverride
            ?? base64_encode(hash_hmac('sha256', $message, $this->callbackKey($secret), true));

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
