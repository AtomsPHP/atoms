<?php

/**
 * Base class for every typed failure the callback channel (`app()` and
 * `dispatch()`) raises. Every subclass carries a catalog code (E080-E084, or
 * the reused E061 for a turn-deadline overrun) rendered through
 * {@see ErrorCatalog::format()}, so the message and its fix line always match
 * `packages/core/resources/errors.json` — unlike {@see AtomsNotSupported},
 * which predates the catalog and does not.
 *
 * `\RuntimeException`, not `\PDOException`: these are not database failures,
 * and a customer catching `\RuntimeException` around `$this->app()`/
 * `$this->dispatch()` is the documented pattern (design doc §2.4).
 *
 * No declare(strict_types=1) — see the note in host.php.
 */

namespace Atoms\Cf;

use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;

class CallbackError extends \RuntimeException
{
    /**
     * @param ErrorCode $code
     * @param array<string, scalar|\Stringable> $context substituted into the catalog message's {placeholders}
     * @param string|null $detail free-text detail appended in parentheses, for information the catalog
     *                            template has no placeholder for (e.g. a host-side diagnostic reason)
     */
    protected function __construct(ErrorCode $code, array $context = [], $detail = null)
    {
        $message = ErrorCatalog::format($code, $context);

        if ($detail !== null && $detail !== '') {
            $message .= ' (' . $detail . ')';
        }

        parent::__construct($message);
    }
}
