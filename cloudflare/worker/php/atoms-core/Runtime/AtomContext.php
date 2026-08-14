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
     * Dispatch a job by class name, with its constructor arguments by name.
     *
     * Takes the class NAME, not an instance, because an AtomJob's source is
     * World B and never ships: the class does not exist on the platform, so
     * there is nothing to construct. `SomeJob::class` is resolved by the
     * compiler from the calling file's own `use` statement, so naming the job
     * neither loads it nor requires it to ship.
     *
     * `$args` is keyed by CONSTRUCTOR PARAMETER NAME — the same key space the
     * wire form and `CallbackKernel::constructJob()` already use — and each
     * value is normalized through the serializer, so only
     * serialization-algebra types cross.
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
