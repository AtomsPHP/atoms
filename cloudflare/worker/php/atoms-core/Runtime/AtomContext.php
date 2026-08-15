<?php

declare(strict_types=1);

namespace Atoms\Runtime;

use Atoms\AtomJob;
use Atoms\Database;
use Atoms\Timers\Timers;

/**
 * Everything an Atom needs from its host runtime, behind one interface. The
 * platform runtime and the test harness each provide an implementation; the
 * {@see \Atoms\Atom} base class delegates its protected accessors here.
 */
interface AtomContext
{
    public function db(): Database;

    /**
     * The `app()` proxy — reverse RPC into the monolith's Methods class.
     */
    public function app(): object;

    /**
     * See {@see \Atoms\Atom::dispatch()} for why this takes a name, not an
     * instance. Implementations must key `$args` by constructor parameter name
     * — the key space `CallbackKernel::constructJob()` reads on the far side —
     * and normalize each value through the serializer.
     *
     * @param class-string<AtomJob> $job
     * @param array<string, mixed> $args
     */
    public function dispatch(string $job, array $args = []): void;

    public function config(string $key): mixed;

    /**
     * @param array<string, mixed> $payload
     */
    public function broadcast(string $channel, array $payload): void;

    /**
     * Named one-shot timers scheduled against this Atom.
     */
    public function timers(): Timers;
}
