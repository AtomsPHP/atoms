<?php

declare(strict_types=1);

namespace Atoms\Errors;

/**
 * Immutable value object describing one entry in the error catalog.
 *
 * @phpstan-type Severity 'error'|'warning'
 */
final class CatalogEntry
{
    /**
     * @param 'error'|'warning' $severity
     */
    public function __construct(
        public readonly ErrorCode $code,
        public readonly string $title,
        public readonly string $message,
        public readonly string $fix,
        public readonly string $docsUrl,
        public readonly string $severity,
        public readonly string $phase,
    ) {
    }
}
