<?php

declare(strict_types=1);

namespace Atoms\Client;

/**
 * Immutable configuration for {@see AtomsClient}, {@see Tickets\TicketClient} and
 * the callback stack. Construct directly or via {@see self::fromArray()}.
 */
final readonly class AtomsConfig
{
    /**
     * @param string      $endpoint               Platform base URL, no trailing slash (e.g. https://edge.atoms.cloud).
     * @param string      $customer               Customer slug; must match the API key's customer.
     * @param string      $apiKey                 Bearer API key (atoms_v1_...).
     * @param float       $timeout                Per-request timeout in seconds (advisory; enforced by the PSR-18 client).
     * @param int         $maxAttempts            Total attempts per logical call (1 = no retries).
     * @param int         $backoffBaseMs          Base delay for exponential backoff, milliseconds.
     * @param bool        $backoffJitter          Whether to apply randomised jitter to backoff delays.
     * @param string|null $platformPublicKey      Ed25519 public key (base64 or raw 32 bytes) for callback verification.
     * @param int         $callbackTimestampWindow Allowed |now - timestamp| skew for callbacks, seconds.
     * @param string|null $manifestPath           Path to a local manifest.json, or null when none is bundled.
     * @param string      $environment            Environment name (e.g. production, staging) for logging/telemetry.
     */
    public function __construct(
        public string $endpoint,
        public string $customer,
        public string $apiKey,
        public float $timeout = 10.0,
        public int $maxAttempts = 3,
        public int $backoffBaseMs = 100,
        public bool $backoffJitter = true,
        public ?string $platformPublicKey = null,
        public int $callbackTimestampWindow = 300,
        public ?string $manifestPath = null,
        public string $environment = 'production',
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            endpoint: rtrim((string) ($data['endpoint'] ?? ''), '/'),
            customer: (string) ($data['customer'] ?? ''),
            apiKey: (string) ($data['apiKey'] ?? $data['api_key'] ?? ''),
            timeout: (float) ($data['timeout'] ?? 10.0),
            maxAttempts: (int) ($data['maxAttempts'] ?? $data['max_attempts'] ?? 3),
            backoffBaseMs: (int) ($data['backoffBaseMs'] ?? $data['backoff_base_ms'] ?? 100),
            backoffJitter: (bool) ($data['backoffJitter'] ?? $data['backoff_jitter'] ?? true),
            platformPublicKey: self::nullableString($data['platformPublicKey'] ?? $data['platform_public_key'] ?? null),
            callbackTimestampWindow: (int) ($data['callbackTimestampWindow'] ?? $data['callback_timestamp_window'] ?? 300),
            manifestPath: self::nullableString($data['manifestPath'] ?? $data['manifest_path'] ?? null),
            environment: (string) ($data['environment'] ?? 'production'),
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

    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
