<?php

declare(strict_types=1);

namespace Atoms\Client;

use Atoms\Client\Crypto\KeyDerivation;

/**
 * Immutable configuration for {@see AtomsClient}, {@see Tickets\TicketIssuer}
 * and the callback stack. Construct directly or via {@see self::fromArray()}.
 *
 * The settings are readonly; the keys derived from the shared secret are
 * computed on first use and cached for the life of the instance.
 */
final class AtomsConfig
{
    /** Memoized {@see self::bearerToken()}. */
    private ?string $bearer = null;

    /** Memoized {@see self::ticketKey()}. */
    private ?string $ticketKeyCache = null;

    /**
     * Memoized {@see self::callbackKeys()}.
     *
     * @var non-empty-list<string>|null
     */
    private ?array $callbackKeyCache = null;

    /**
     * @param string      $endpoint               Worker base URL, no trailing slash (e.g. https://atoms.<your-subdomain>.workers.dev).
     * @param string      $sharedSecret           ATOMS_SHARED_SECRET: base64 of 32 random bytes, identical on the app and the Worker — see below.
     * @param float       $timeout                Per-request timeout in seconds (advisory; enforced by the PSR-18 client).
     * @param int         $maxAttempts            Total attempts per logical call (1 = no retries).
     * @param int         $backoffBaseMs          Base delay for exponential backoff, milliseconds.
     * @param bool        $backoffJitter          Whether to apply randomised jitter to backoff delays.
     * @param string|null $sharedSecretPrevious   ATOMS_SHARED_SECRET_PREVIOUS: the rotation overlap secret — see below.
     * @param int         $callbackTimestampWindow Allowed |now - timestamp| skew for callbacks, seconds.
     * @param string|null $manifestPath           Path to a local manifest.json, or null when none is bundled.
     * @param string      $environment            Environment name (e.g. production, staging) for logging/telemetry.
     * @param int         $wsTicketTtlMs          Lifetime stamped into a WebSocket ticket's `exp`, milliseconds — see below.
     *
     * $sharedSecret is the single root of the app ↔ Worker boundary
     * (docs/shared-secret.md). It is required, and validated here: trimmed of
     * ASCII whitespace, strict base64, exactly 32 decoded bytes. Every key
     * comes from it by HKDF-SHA256 — {@see self::bearerToken()} for the
     * `Authorization` header, {@see self::callbackKeys()} for verifying
     * inbound callbacks. The secret itself stays on the hosts that hold it;
     * `atoms token` prints the derived bearer for hand-issued requests.
     *
     * The bearer goes out on every call. A Worker running
     * `ATOMS_BEARER_AUTH=disabled` — the posture for an authenticating proxy
     * such as Cloudflare Access in front of it — ignores the header.
     *
     * $sharedSecretPrevious widens callback *acceptance* during a rotation:
     * {@see self::callbackKeys()} returns both keys, and a callback signed
     * under either verifies. It never affects what this app sends — the
     * bearer is always derived from $sharedSecret. Same format rules when set.
     *
     * $wsTicketTtlMs is how long a ticket {@see Tickets\TicketIssuer} issues
     * stays valid. The application owns this now, because the application
     * computes `exp`; the Worker only compares it against its own clock. Keep
     * it short — a ticket is reusable until it expires, and that brevity is
     * the whole defence against a leaked connection URL.
     *
     * @throws \InvalidArgumentException when either secret is absent or malformed (ATOMS-E105),
     *                                   or when $wsTicketTtlMs is not positive.
     */
    public function __construct(
        public readonly string $endpoint,
        public readonly string $sharedSecret,
        public readonly float $timeout = 10.0,
        public readonly int $maxAttempts = 3,
        public readonly int $backoffBaseMs = 100,
        public readonly bool $backoffJitter = true,
        public readonly ?string $sharedSecretPrevious = null,
        public readonly int $callbackTimestampWindow = 300,
        public readonly ?string $manifestPath = null,
        public readonly string $environment = 'production',
        public readonly int $wsTicketTtlMs = 60000,
    ) {
        KeyDerivation::decodeSecret($sharedSecret);

        if ($sharedSecretPrevious !== null) {
            KeyDerivation::decodeSecret($sharedSecretPrevious, 'ATOMS_SHARED_SECRET_PREVIOUS');
        }

        if ($wsTicketTtlMs <= 0) {
            throw new \InvalidArgumentException(sprintf(
                'wsTicketTtlMs must be a positive number of milliseconds, got %d. A ticket expires when the '
                . 'Worker\'s clock reaches its "exp", so a non-positive lifetime could never be presented in time.',
                $wsTicketTtlMs,
            ));
        }
    }

    /**
     * Build from a loose config array (framework config, env, etc.). Both
     * camelCase and snake_case keys are read.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            endpoint: rtrim((string) ($data['endpoint'] ?? ''), '/'),
            sharedSecret: (string) ($data['sharedSecret'] ?? $data['shared_secret'] ?? ''),
            timeout: (float) ($data['timeout'] ?? 10.0),
            maxAttempts: (int) ($data['maxAttempts'] ?? $data['max_attempts'] ?? 3),
            backoffBaseMs: (int) ($data['backoffBaseMs'] ?? $data['backoff_base_ms'] ?? 100),
            backoffJitter: (bool) ($data['backoffJitter'] ?? $data['backoff_jitter'] ?? true),
            sharedSecretPrevious: self::nullableString($data['sharedSecretPrevious'] ?? $data['shared_secret_previous'] ?? null),
            callbackTimestampWindow: (int) ($data['callbackTimestampWindow'] ?? $data['callback_timestamp_window'] ?? 300),
            manifestPath: self::nullableString($data['manifestPath'] ?? $data['manifest_path'] ?? null),
            environment: (string) ($data['environment'] ?? 'production'),
            wsTicketTtlMs: (int) ($data['wsTicketTtlMs'] ?? $data['ws_ticket_ttl_ms'] ?? 60000),
        );
    }

    /**
     * Normalise the endpoint (strip a trailing slash) if one slipped through the
     * direct constructor.
     */
    public function baseUrl(): string
    {
        return rtrim($this->endpoint, '/');
    }

    /**
     * The `Authorization: Bearer` value for this configuration: standard
     * base64 (44 characters) of HKDF(secret, "atoms/bearer/v1"). Derived from
     * $sharedSecret only — a sender emits the current value.
     */
    public function bearerToken(): string
    {
        return $this->bearer ??= KeyDerivation::bearerToken($this->sharedSecret);
    }

    /**
     * Raw 32-byte HMAC-SHA256 keys for verifying inbound callbacks: the
     * current secret's key, plus the previous secret's when the rotation
     * overlap is configured. Verification tries each in turn.
     *
     * @return non-empty-list<string>
     */
    public function callbackKeys(): array
    {
        return $this->callbackKeyCache ??= KeyDerivation::callbackKeys(
            $this->sharedSecret,
            $this->sharedSecretPrevious,
        );
    }

    /**
     * The raw 32-byte HMAC-SHA256 key WebSocket tickets are signed with:
     * HKDF(secret, "atoms/ws-ticket/v1"). Derived from $sharedSecret only — a
     * sender emits under the current secret, even mid-rotation.
     */
    public function ticketKey(): string
    {
        return $this->ticketKeyCache ??= KeyDerivation::ticketKey($this->sharedSecret);
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
