<?php

declare(strict_types=1);

namespace Atoms\Tests\Integration\Adapters\Support;

/**
 * One Ed25519 keypair per test process (generated lazily, once, in static
 * state): the suite plays the platform's role as signer, so every host under
 * test is handed only {@see self::publicKeyBase64()} — exactly what a real
 * deployment would configure — while this class holds the private half and
 * signs on the suite's behalf.
 *
 * Mirrors the signing mechanics {@see \Atoms\Client\Tests\Callback\CallbackKernelTest}
 * uses directly (`"v1\n{ts}\n{nonce}\n{body}"`, detached Ed25519, base64), kept
 * here as a small reusable helper instead of a private per-test method so
 * every AdapterHost's cases can share it.
 */
final class CallbackSigner
{
    private static ?string $publicKey = null;

    private static ?string $secretKey = null;

    public function __construct()
    {
        self::ensureKeypair();
    }

    public function publicKeyBase64(): string
    {
        self::ensureKeypair();

        return base64_encode((string) self::$publicKey);
    }

    /**
     * Build the four callback headers for `$body` signed as `$kind`, over the
     * exact bytes given — no re-encoding. `X-Atoms-Kind`'s value is whatever
     * the caller passes; it is not part of the signed message (only
     * `"v1\n{ts}\n{nonce}\n{body}"` is), matching CallbackKernel::handle().
     *
     * @return array<string, string>
     */
    public function sign(string $timestamp, string $nonce, string $body, string $kind): array
    {
        self::ensureKeypair();

        $message = "v1\n" . $timestamp . "\n" . $nonce . "\n" . $body;
        $signature = base64_encode(sodium_crypto_sign_detached($message, (string) self::$secretKey));

        return [
            'X-Atoms-Kind' => $kind,
            'X-Atoms-Timestamp' => $timestamp,
            'X-Atoms-Nonce' => $nonce,
            'X-Atoms-Signature' => $signature,
        ];
    }

    /**
     * A fresh 32-hex-char nonce, matching the shape CallbackKernel expects.
     */
    public function newNonce(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * The current unix timestamp, as the string CallbackKernel reads.
     */
    public function now(): string
    {
        return (string) time();
    }

    private static function ensureKeypair(): void
    {
        if (self::$publicKey !== null) {
            return;
        }

        $keypair = sodium_crypto_sign_keypair();
        self::$publicKey = sodium_crypto_sign_publickey($keypair);
        self::$secretKey = sodium_crypto_sign_secretkey($keypair);
    }
}
