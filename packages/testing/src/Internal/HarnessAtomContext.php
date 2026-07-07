<?php

declare(strict_types=1);

namespace Atoms\Testing\Internal;

use Atoms\AtomJob;
use Atoms\Database;
use Atoms\Runtime\AtomContext;

/**
 * The {@see AtomContext} implementation an {@see \Atoms\Testing\AtomHarness}
 * hands to the Atom it constructs: a real temp-file SQLite database, the fake
 * app() proxy, provided config values, and closures recording dispatch()/
 * broadcast() calls back into the harness.
 *
 * @internal
 */
final class HarnessAtomContext implements AtomContext
{
    /**
     * @param array<string, mixed> $config
     * @param \Closure(AtomJob): void $onDispatch
     * @param \Closure(string, array<string, mixed>): void $onBroadcast
     */
    public function __construct(
        private readonly Database $database,
        private readonly object $appProxy,
        private readonly array $config,
        private readonly \Closure $onDispatch,
        private readonly \Closure $onBroadcast,
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

    public function config(string $key): mixed
    {
        return $this->config[$key] ?? null;
    }

    public function broadcast(string $channel, array $payload): void
    {
        ($this->onBroadcast)($channel, $payload);
    }
}
