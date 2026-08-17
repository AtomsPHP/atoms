<?php

declare(strict_types=1);

namespace Atoms\Client\Internal;

/**
 * The `error` object of a platform response body, destructured exactly once.
 *
 * The Worker answers a failure as `{"error": {"code", "message", "retryable",
 * ...}}`, and may add `remote_class`/`remote_trace` when the failure was an
 * exception thrown by the customer's own Atom. Reading those keys is the only
 * place in {@see \Atoms\Client\AtomsClient} that knows the envelope's shape:
 * everything downstream — the 200-with-error-frame check, the exception
 * mapping, and the retry decision — works from an instance of this class.
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
        public readonly ?string $remoteTrace,
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
            return new self(false, '', '', null, null, false);
        }

        return new self(
            true,
            (string) ($error['code'] ?? ''),
            (string) ($error['message'] ?? ''),
            isset($error['remote_class']) ? (string) $error['remote_class'] : null,
            isset($error['remote_trace']) ? (string) $error['remote_trace'] : null,
            ($error['retryable'] ?? false) === true,
        );
    }
}
