<?php

declare(strict_types=1);

namespace Atoms\Client\Crypto;

use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;

/**
 * Derives the keys on the app ↔ Worker boundary from the shared secret
 * (docs/shared-secret.md).
 *
 * `ATOMS_SHARED_SECRET` is 32 random bytes, base64-encoded, configured
 * identically on the app and the Worker. Each purpose gets its own key
 * through HKDF-SHA256: empty salt, a fixed `info` string, 32 bytes of output,
 * over the *decoded* secret. Decoding first is what makes PHP's `hash_hkdf()`
 * and the Worker's WebCrypto `deriveBits()` agree byte for byte.
 *
 * The secret stays on the two hosts that hold it. What travels is the derived
 * bearer, and HKDF's one-wayness keeps a leaked bearer from yielding the
 * secret or any sibling key.
 */
final class KeyDerivation
{
    /** HKDF `info` for the bearer the app sends and the Worker compares. */
    public const BEARER_INFO = 'atoms/bearer/v1';

    /** HKDF `info` for the HMAC-SHA256 key protecting inbound callbacks. */
    public const CALLBACK_INFO = 'atoms/callback/v1';

    /** HKDF `info` for the HMAC-SHA256 key WebSocket connection tickets are signed with. */
    public const TICKET_INFO = 'atoms/ws-ticket/v1';

    /** Decoded length of the shared secret, and of every key derived from it. */
    public const SECRET_BYTES = 32;

    private function __construct()
    {
    }

    /**
     * Decode a configured secret into its 32 raw bytes of key material.
     *
     * Surrounding ASCII whitespace is trimmed; the remainder must be strict
     * base64 decoding to exactly {@see self::SECRET_BYTES} bytes.
     *
     * @param string $setting Name of the setting being validated, for the error message.
     *
     * @throws \InvalidArgumentException when $secret is absent or malformed.
     */
    public static function decodeSecret(string $secret, string $setting = 'ATOMS_SHARED_SECRET'): string
    {
        $decoded = base64_decode(trim($secret), true);

        if ($decoded === false || strlen($decoded) !== self::SECRET_BYTES) {
            throw new \InvalidArgumentException(sprintf(
                '%s Setting: %s. The secret stays on your hosts: what goes on the wire is the bearer '
                . 'derived from it, which `atoms token` prints.',
                ErrorCatalog::format(ErrorCode::SharedSecretMissing),
                $setting,
            ));
        }

        return $decoded;
    }

    /**
     * The `Authorization: Bearer` value: standard base64 (44 characters) of
     * HKDF(secret, {@see self::BEARER_INFO}). The Worker derives the same
     * value from its own copy of the secret and compares in constant time.
     *
     * @throws \InvalidArgumentException when $secret is absent or malformed.
     */
    public static function bearerToken(string $secret, string $setting = 'ATOMS_SHARED_SECRET'): string
    {
        return base64_encode(self::derive(self::decodeSecret($secret, $setting), self::BEARER_INFO));
    }

    /**
     * The raw 32-byte HMAC-SHA256 key inbound callbacks are signed with.
     *
     * @throws \InvalidArgumentException when $secret is absent or malformed.
     */
    public static function callbackKey(string $secret, string $setting = 'ATOMS_SHARED_SECRET'): string
    {
        return self::derive(self::decodeSecret($secret, $setting), self::CALLBACK_INFO);
    }

    /**
     * The raw 32-byte HMAC-SHA256 key WebSocket connection tickets are signed
     * with (docs/ws-ticket-protocol.md). Derived from the current secret only:
     * this app issues its own tickets, and a sender emits under the current
     * secret. The Worker verifies under this key and, during a rotation
     * overlap, under the previous secret's as well.
     *
     * @throws \InvalidArgumentException when $secret is absent or malformed.
     */
    public static function ticketKey(string $secret, string $setting = 'ATOMS_SHARED_SECRET'): string
    {
        return self::derive(self::decodeSecret($secret, $setting), self::TICKET_INFO);
    }

    /**
     * Callback verification keys: the current secret's key, followed by the
     * previous secret's when a rotation overlap is configured. A verifier
     * tries every key in turn; a sender emits under the current secret only.
     *
     * @return non-empty-list<string> raw 32-byte HMAC-SHA256 keys
     *
     * @throws \InvalidArgumentException when either secret is absent or malformed.
     */
    public static function callbackKeys(string $secret, ?string $previous = null): array
    {
        $keys = [self::callbackKey($secret)];

        if ($previous !== null) {
            $keys[] = self::callbackKey($previous, 'ATOMS_SHARED_SECRET_PREVIOUS');
        }

        return $keys;
    }

    private static function derive(string $ikm, string $info): string
    {
        return hash_hkdf('sha256', $ikm, self::SECRET_BYTES, $info, '');
    }
}
