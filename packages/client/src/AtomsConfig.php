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
     * @param string      $endpoint               Worker base URL, no trailing slash (e.g. https://atoms.<your-subdomain>.workers.dev).
     * @param string|null $apiKey                 Bearer key matching the Worker's ATOMS_APP_KEY, or null when the
     *                                            Worker runs with auth disabled (ATOMS_APP_KEY unset) — see below.
     * @param float       $timeout                Per-request timeout in seconds (advisory; enforced by the PSR-18 client).
     * @param int         $maxAttempts            Total attempts per logical call (1 = no retries).
     * @param int         $backoffBaseMs          Base delay for exponential backoff, milliseconds.
     * @param bool        $backoffJitter          Whether to apply randomised jitter to backoff delays.
     * @param string|null $platformPublicKey      Ed25519 public key (base64 or raw 32 bytes) for callback verification.
     * @param int         $callbackTimestampWindow Allowed |now - timestamp| skew for callbacks, seconds.
     * @param string|null $manifestPath           Path to a local manifest.json, or null when none is bundled.
     * @param string      $environment            Environment name (e.g. production, staging) for logging/telemetry.
     *
     * $apiKey has three distinct states, and the distinction is deliberate:
     *
     * - a non-empty string — send `Authorization: Bearer <key>`;
     * - `null` — *explicitly unauthenticated*: send no Authorization header at
     *   all. This is the shape that matches a Worker deployed with
     *   `ATOMS_APP_KEY` unset (the local-dev default), or one fronted by
     *   Cloudflare Access instead of a bearer key;
     * - `''` — a configuration error, not a posture, and rejected here. An
     *   empty key almost always means an env var silently resolved to empty;
     *   left alone it would ship `Authorization: Bearer ` — accepted by an
     *   auth-off Worker and confusingly rejected by a real one. Fail loudly at
     *   construction instead. Pass null if you mean "no auth".
     *
     * @throws \InvalidArgumentException when $apiKey is an empty string.
     */
    public function __construct(
        public string $endpoint,
        public ?string $apiKey,
        public float $timeout = 10.0,
        public int $maxAttempts = 3,
        public int $backoffBaseMs = 100,
        public bool $backoffJitter = true,
        public ?string $platformPublicKey = null,
        public int $callbackTimestampWindow = 300,
        public ?string $manifestPath = null,
        public string $environment = 'production',
    ) {
        if ($apiKey === '') {
            throw new \InvalidArgumentException(
                'Atoms API key is an empty string. That is never a valid posture: it would send '
                . '"Authorization: Bearer " with no credential. Set a real key matching the Worker\'s '
                . 'ATOMS_APP_KEY, or pass null explicitly if the Worker runs with auth disabled '
                . '(ATOMS_APP_KEY unset).',
            );
        }
    }

    /**
     * Build from a loose config array (framework config, env, etc.).
     *
     * The apiKey lookup deliberately does NOT coerce or default to '': an
     * absent key and an explicit null both mean "unauthenticated", while a
     * present-but-empty string is a configuration error and still throws from
     * the constructor.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $apiKey = $data['apiKey'] ?? $data['api_key'] ?? null;

        return new self(
            endpoint: rtrim((string) ($data['endpoint'] ?? ''), '/'),
            apiKey: $apiKey === null ? null : (string) $apiKey,
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

    /**
     * True when calls should carry an Authorization header. False means the
     * caller explicitly configured no auth (see {@see self::__construct()}).
     */
    public function isAuthenticated(): bool
    {
        return $this->apiKey !== null;
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
