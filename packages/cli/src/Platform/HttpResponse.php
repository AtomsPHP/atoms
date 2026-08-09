<?php

declare(strict_types=1);

namespace Atoms\Cli\Platform;

/**
 * A platform HTTP response: status code, decoded JSON body, and raw bytes.
 */
final class HttpResponse
{
    /**
     * @param array<string, mixed> $json
     */
    public function __construct(
        public readonly int $status,
        public readonly array $json,
        public readonly string $raw = '',
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * The `error` envelope from the API contract, if present.
     *
     * @return array{code?: string, message?: string, retryable?: bool}|null
     */
    public function error(): ?array
    {
        $error = $this->json['error'] ?? null;

        return \is_array($error) ? $error : null;
    }
}
