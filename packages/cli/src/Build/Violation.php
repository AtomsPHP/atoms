<?php

declare(strict_types=1);

namespace Atoms\Cli\Build;

use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;

/**
 * A single build-time finding: a stable ATOMS-E### code, the file/line it
 * applies to, and the context needed to render the catalog message.
 */
final class Violation
{
    /**
     * @param array<string, scalar|\Stringable> $context
     */
    public function __construct(
        public readonly ErrorCode $code,
        public readonly string $file,
        public readonly int $line,
        public readonly array $context = [],
        public readonly ?string $symbol = null,
    ) {
    }

    public function isError(): bool
    {
        return ErrorCatalog::get($this->code)->severity === 'error';
    }

    public function isWarning(): bool
    {
        return ErrorCatalog::get($this->code)->severity === 'warning';
    }

    public function message(): string
    {
        return ErrorCatalog::format($this->code, $this->context);
    }

    /** De-duplication key: (code, symbol, file). */
    public function dedupeKey(): string
    {
        return $this->code->value . '|' . ($this->symbol ?? '') . '|' . $this->file;
    }
}
