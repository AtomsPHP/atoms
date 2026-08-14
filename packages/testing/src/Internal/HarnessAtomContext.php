<?php

declare(strict_types=1);

namespace Atoms\Testing\Internal;

use Atoms\AtomJob;
use Atoms\Database;
use Atoms\Runtime\AtomContext;
use Atoms\Testing\FakeTimers;
use Atoms\Timers\Timers;

/**
 * The {@see AtomContext} implementation an {@see \Atoms\Testing\AtomHarness}
 * hands to the Atom it constructs: a real temp-file SQLite database, the fake
 * app() proxy, provided config values, closures recording dispatch()/
 * broadcast() calls back into the harness, and a {@see FakeTimers}.
 *
 * @internal
 */
final class HarnessAtomContext implements AtomContext
{
    /**
     * @param array<string, mixed> $config
     * @param \Closure(AtomJob): void $onDispatch
     * @param \Closure(string, array<string, mixed>): void $onDispatchJob
     * @param \Closure(string, array<string, mixed>): void $onBroadcast
     */
    public function __construct(
        private readonly Database $database,
        private readonly object $appProxy,
        private readonly array $config,
        private readonly \Closure $onDispatch,
        private readonly \Closure $onDispatchJob,
        private readonly \Closure $onBroadcast,
        private readonly FakeTimers $timers,
    ) {
    }

    public function db(): Database
    {
        return $this->database;
    }

    public function app(): object
    {
        return $this->appProxy;
    }

    public function dispatch(AtomJob $job): void
    {
        ($this->onDispatch)($job);
    }

    public function dispatchJob(string $job, array $args = []): void
    {
        ($this->onDispatchJob)(ltrim(trim($job), '\\'), $args);
    }

    public function config(string $key): mixed
    {
        return $this->config[$key] ?? null;
    }

    public function broadcast(string $channel, array $payload): void
    {
        ($this->onBroadcast)($channel, $payload);
    }

    public function timers(): Timers
    {
        return $this->timers;
    }
}
