<?php

declare(strict_types=1);

namespace Atoms\Client;

/**
 * Per-call options for an Atom invocation.
 *
 * Passed to {@see AtomsClient::get()} rather than exposed as fluent methods on
 * {@see AtomProxy} — see {@see AtomProxy} for why the proxy's declared methods
 * are permanently limited to `__construct`, `__call` and `__get`.
 */
final class CallOptions
{
    /**
     * @param bool $retryTurnDeadline Treat `turn_deadline_exceeded` as retryable. Off by default: a
     *                                turn that ran out of time may already have committed, so retrying
     *                                is only safe when the method is idempotent.
     */
    public function __construct(
        public readonly bool $retryTurnDeadline = false,
    ) {
    }
}
