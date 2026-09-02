<?php

declare(strict_types=1);

namespace Atoms;

use Atoms\Runtime\AtomContext;
use Atoms\Timers\Timers;
use Atoms\Websocket\Connection;
use Atoms\Websocket\Message;

/**
 * Base class for every Atom. This is the entire Atom-side runtime surface: what an
 * Atom class is allowed to see. Everything here is frozen API — a change to a
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
     * The reverse-RPC proxy into this Atom's Methods class (App-side).
     *
     * @return TApp
     */
    protected function app(): object
    {
        return $this->context->app();
    }

    /**
     * Dispatch a job to your app's queue:
     *
     *     $this->dispatch(RecordGameResult::class, ['ref' => $ref, 'seat' => 1]);
     *
     * By class name, never an instance: an AtomJob's code stays in your app and
     * is not on the platform, so there is nothing here to `new`. A `::class`
     * constant is resolved by the compiler, so naming one costs nothing. The
     * runtime sends `{"job":FQCN,"args":{...}}`; your app rebuilds the object.
     *
     * @param class-string<AtomJob> $job
     * @param array<string, mixed> $args keyed by constructor parameter name
     */
    protected function dispatch(string $job, array $args = []): void
    {
        $this->context->dispatch($job, $args);
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
