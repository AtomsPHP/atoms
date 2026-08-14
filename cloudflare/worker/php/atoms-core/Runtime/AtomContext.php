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
     * Dispatch a job the caller has already constructed.
     *
     * World B only. An AtomJob's source does not ship to the platform (its
     * `handle()` runs in the monolith), so an Atom cannot `new` one — the build
     * refuses `$this->dispatch(new SomeJob(...))` inside Atom code with
     * `ATOMS-E104` and points at {@see self::dispatchJob()}. This overload
     * remains for the hosts where the class genuinely is loaded: the test
     * harness and anything driving an Atom in-process.
     */
    public function dispatch(AtomJob $job): void;

    /**
     * Dispatch a job by class name, with its constructor arguments by name.
     *
     * The World A form, and the only one an Atom can use: `SomeJob::class` is a
     * compile-time constant, so naming the class neither loads it nor requires
     * it to ship. `$args` is keyed by CONSTRUCTOR PARAMETER NAME — the same key
     * space the wire form and `CallbackKernel::constructJob()` already use —
     * and each value is normalized through the serializer, so only
     * serialization-algebra types cross.
     *
     * @param class-string<AtomJob> $job
     * @param array<string, mixed> $args
     */
    public function dispatchJob(string $job, array $args = []): void;

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
