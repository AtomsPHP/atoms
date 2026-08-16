<?php

declare(strict_types=1);

namespace Atoms\Client\Callback;

/**
 * Verifies HMAC-SHA256 tags on inbound callbacks against the keys derived
 * from the shared secret ({@see \Atoms\Client\Crypto\KeyDerivation}).
 *
 * A tag is 32 bytes; anything else is refused before any comparison.
 */
final class HmacVerifier
{
    /** Length of an HMAC-SHA256 tag, in bytes. */
    private const TAG_BYTES = 32;

    /**
     * @param non-empty-list<string> $keys Raw 32-byte HMAC-SHA256 keys: the current secret's, then the previous secret's during a rotation overlap.
     */
    public function __construct(private readonly array $keys)
    {
    }

    /**
     * @param string $signature Raw 32-byte HMAC-SHA256 tag (already base64-decoded).
     */
    public function verify(string $message, string $signature): bool
    {
        if (strlen($signature) !== self::TAG_BYTES) {
            return false;
        }

        // Try-both, never a key selector: every configured key is attempted in
        // order and the first match wins. A key id is not a trusted input, so
        // nothing the caller sends chooses which key is used.
        foreach ($this->keys as $key) {
            if (hash_equals(hash_hmac('sha256', $message, $key, true), $signature)) {
                return true;
            }
        }

        return false;
    }
}
