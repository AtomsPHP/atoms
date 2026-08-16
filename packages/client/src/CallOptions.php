<?php

declare(strict_types=1);

namespace Atoms\Client;

/**
 * Per-call options for an Atom invocation.
 *
 * These exist so the proxy covers every call site. Without them the only way to
 * set `retryTurnDeadline` was the positional
 * `call('GameRoom', $id, 'method', [$a, $b], GameRoom::class, true)` form, which
 * names the Atom twice and loses the return type; with them the elegant path
 * stays elegant:
 *
 *     Atoms::get(GameRoom::class, $id, new CallOptions(retryTurnDeadline: true))
 *         ->recordResult($score);
 *
 * Deliberately passed to {@see AtomsClient::get()} rather than exposed as
 * fluent methods on {@see AtomProxy}. A declared method on the proxy beats
 * `__call()` silently, so `$proxy->retryingTurnDeadline()` would make an Atom
 * method of that name permanently unreachable, with no error at either end —
 * see docs/conventions.md §The proxy declares nothing.
 *
 * Construct with named arguments; every option has a default that matches the
 * behaviour of a call with no options at all.
 */
final class CallOptions
{
    /**
     * @param bool $retryTurnDeadline Treat `turn_deadline_exceeded` as retryable for this call.
     *                                Off by default because a turn that ran out of time may have
     *                                committed: retrying is only safe when the method is idempotent,
     *                                which is a property of your code, not of the transport.
     * @param string|null $idempotencyKey Reuse a specific `Idempotency-Key` instead of a fresh random
     *                                    one, so a retry from your own queue is recognisably the same
     *                                    call. Must be unique per logical call.
     * @param string|null $traceparent W3C traceparent for this call only, overriding
     *                                 {@see AtomsClient::setTraceparent()}.
     */
    public function __construct(
        public readonly bool $retryTurnDeadline = false,
        public readonly ?string $idempotencyKey = null,
        public readonly ?string $traceparent = null,
    ) {
    }
}
