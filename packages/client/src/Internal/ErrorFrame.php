<?php

declare(strict_types=1);

namespace Atoms\Client\Internal;

/**
 * The `error` object of a platform response body, destructured exactly once.
 *
 * A failure arrives as `{"error": {"code", "message", "retryable", ...}}`.
 * Reading those keys is the only place in {@see \Atoms\Client\AtomsClient} that
 * knows the envelope's shape: everything downstream — the 200-with-error-frame
 * check, the exception mapping, and the retry decision — works from an instance
 * of this class.
 *
 * The Worker reports the throwing class as `class` and sends no trace.
 * Only `atom_exception` identifies a customer exception: runtime failures
 * can also carry `class` and must retain their code-based mapping.
 *
 * @internal Not part of `atoms/client`'s public surface. Its shape may change
 *           with the wire protocol; nothing outside this package may depend on it.
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
