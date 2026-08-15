<?php

declare(strict_types=1);

namespace Atoms\Tests\Integration\Adapters\Support;

/**
 * Signs callback envelopes the way a Worker configured with a given shared
 * secret does: HKDF-SHA256 derives the 32-byte callback key from the secret
 * (info `atoms/callback/v1`, empty salt), and HMAC-SHA256 signs
 * `"v1\n{ts}\n{nonce}\n" . $body`, base64-encoded into `X-Atoms-Signature`.
 *
 * Defaults to {@see self::DEFAULT_SHARED_SECRET} — the reference vector
 * `docs/shared-secret.md` records — so a host booted with
 * {@see \Atoms\Tests\Integration\Adapters\Host\HostOptions}'s own default and
 * a signer built with no arguments agree without either side threading a
 * secret through explicitly. Mirrors the signing mechanics
 * {@see \Atoms\Client\Tests\Callback\CallbackKernelTest} uses directly
 * (`"v1\n{ts}\n{nonce}\n{body}"`, HMAC-SHA256, base64), kept here as a small
 * reusable helper instead of a private per-test method so every AdapterHost's
 * cases can share it.
 */
final class CallbackSigner
{
    /** The reference vector `docs/shared-secret.md` records: bytes 0x00-0x1f, base64. */
    public const DEFAULT_SHARED_SECRET = 'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=';

    /** Length, in bytes, of an HMAC-SHA256 tag — the shape a signature override must match to look plausible. */
    public const TAG_LENGTH_BYTES = 32;

    private const CALLBACK_INFO = 'atoms/callback/v1';

    private readonly string $sharedSecret;

    private readonly string $callbackKey;

    public function __construct(string $sharedSecretBase64 = self::DEFAULT_SHARED_SECRET)
    {
        $decoded = base64_decode($sharedSecretBase64, true);

        if ($decoded === false || strlen($decoded) !== 32) {
            throw new \InvalidArgumentException(
                'CallbackSigner requires a base64-encoded 32-byte shared secret.',
            );
        }

        $this->sharedSecret = $sharedSecretBase64;
        $this->callbackKey = hash_hkdf('sha256', $decoded, 32, self::CALLBACK_INFO, '');
    }

    /**
     * The base64 shared secret this signer signs under — thread this into a
     * host's {@see \Atoms\Tests\Integration\Adapters\Host\HostOptions::$sharedSecret}
     * (or `$sharedSecretPrevious`) so the host verifies against the same key
     * this signer signs with.
     */
    public function sharedSecretBase64(): string
    {
        return $this->sharedSecret;
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
        $message = "v1\n" . $timestamp . "\n" . $nonce . "\n" . $body;
        $signature = base64_encode(hash_hmac('sha256', $message, $this->callbackKey, true));

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
}
