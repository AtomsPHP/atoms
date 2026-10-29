<?php

declare(strict_types=1);

namespace Atoms\Client\Internal;

/**
 * Parsed Worker error. Only `atom_exception` identifies an Atom exception;
 * runtime failures can also include a `class` field.
 *
 * @internal
 */
final class ErrorFrame
{
    private function __construct(
        /** Whether the body carried an `error` object at all. */
        public readonly bool $present,
        public readonly string $code,
        public readonly string $message,
        /** Set only when the failure was an exception thrown by a customer Atom. */
        public readonly ?string $remoteClass,
        /** The platform's own `retryable` flag, true only when literally `true`. */
        public readonly bool $platformRetryable,
    ) {
    }

    /**
     * @param array<array-key, mixed> $decoded The whole decoded response body.
     */
    public static function fromBody(array $decoded): self
    {
        $error = $decoded['error'] ?? null;

        if (!is_array($error)) {
            return new self(false, '', '', null, false);
        }

        return new self(
            true,
            (string) ($error['code'] ?? ''),
            (string) ($error['message'] ?? ''),
            ($error['code'] ?? null) === 'atom_exception' && isset($error['class'])
                ? (string) $error['class'] : null,
            ($error['retryable'] ?? false) === true,
        );
    }
}
