<?php

declare(strict_types=1);

namespace Atoms\Client\Callback;

/**
 * Verifies detached Ed25519 signatures against a fixed platform public key,
 * using libsodium. The key may be supplied base64-encoded or as raw 32 bytes.
 */
final class Ed25519Verifier
{
    /** Raw 32-byte public key. */
    private readonly string $publicKey;

    public function __construct(string $publicKey)
    {
        $raw = strlen($publicKey) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            ? $publicKey
            : base64_decode($publicKey, true);

        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid Ed25519 public key: expected %d raw bytes (or their base64 encoding).',
                SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES,
            ));
        }

        $this->publicKey = $raw;
    }

    /**
     * @param string $signature Raw 64-byte detached signature (already base64-decoded).
     */
    public function verify(string $message, string $signature): bool
    {
        if (strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($signature, $message, $this->publicKey);
    }
}
