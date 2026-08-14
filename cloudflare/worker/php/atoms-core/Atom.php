<?php

declare(strict_types=1);

namespace Atoms;

use Atoms\Runtime\AtomContext;
use Atoms\Timers\Timers;
use Atoms\Websocket\Connection;
use Atoms\Websocket\Message;

/**
 * Base class for every Atom. This is the entire World A runtime surface: what an
 * Atom class is allowed to see. Everything here is frozen ABI — a change to a
 * signature is a breaking change to every deployed customer bundle.
 *
 * Subclasses receive an {@see AtomContext} from the runtime and reach the
 * platform only through the protected accessors below.
 *
 * @template TApp of AtomMethods
 */
abstract class Atom
{
    public readonly string $id;

    private readonly AtomContext $context;

    final public function __construct(string $id, AtomContext $context)
    {
        $this->id = $id;
        $this->context = $context;
    }

    protected function db(): Database
    {
        return $this->context->db();
    }

    /**
     * The reverse-RPC proxy into this Atom's Methods class (World B).
     *
     * @return TApp
     */
    protected function app(): object
    {
        return $this->context->app();
    }

    /**
     * Dispatch an already-constructed job.
     *
     * Not usable from Atom code: an AtomJob's source stays in the monolith, so
     * the class this needs an instance of is not loaded on the platform. The
     * build says so (`ATOMS-E104`) rather than letting it fail at runtime with
     * `Class "..." not found`. Reach for {@see self::dispatchJob()} instead.
     */
    protected function dispatch(AtomJob $job): void
    {
        $this->context->dispatch($job);
    }

    /**
     * Dispatch a job by class name, passing its constructor arguments by name:
     *
     *     $this->dispatchJob(RecordGameResult::class, ['ref' => $ref, 'seat' => 1]);
     *
     * `RecordGameResult::class` is a compile-time constant — naming the job
     * neither loads it nor drags its `handle()` (and everything that body
     * imports) onto the platform. The runtime sends `{"job":FQCN,"args":{...}}`
     * and your app reconstructs the real object from it.
     *
     * @param class-string<AtomJob> $job
     * @param array<string, mixed> $args keyed by constructor parameter name
     */
    protected function dispatchJob(string $job, array $args = []): void
    {
        $this->context->dispatchJob($job, $args);
    }

    protected function config(string $key): mixed
    {
        return $this->context->config($key);
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function broadcast(string $channel, array $payload): void
    {
        $this->context->broadcast($channel, $payload);
    }

    protected function timers(): Timers
    {
        return $this->context->timers();
    }

    // Lifecycle hooks — invoked by the runtime via Atoms\Runtime\LifecycleInvoker.

    protected function onActivation(): void
    {
    }

    protected function onDeactivation(): void
    {
    }

    /**
     * Invoked by the runtime via {@see \Atoms\Runtime\LifecycleInvoker} when a
     * scheduled timer fires.
     */
    protected function onTimer(string $name): void
    {
    }

    // WebSocket handlers — optional overrides.

    /**
     * @param array<string, string> $params
     */
    public function onConnect(Connection $conn, array $params): void
    {
    }

    public function onMessage(Connection $conn, Message $msg): void
    {
    }

    public function onDisconnect(Connection $conn): void
    {
    }
}
