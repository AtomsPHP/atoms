<?php

declare(strict_types=1);

namespace Atoms\Core\Tests\Fixtures;

use Atoms\AtomJob;
use Atoms\Database;
use Atoms\Runtime\AtomContext;
use Atoms\Timers\Timers;

/**
 * A recording {@see AtomContext} for exercising the Atom base class in isolation.
 * Also implements {@see Timers} directly and hands back `$this`, recording
 * schedule()/cancel() calls the same way dispatch()/broadcast() do above.
 */
final class StubAtomContext implements AtomContext, Timers
{
    /** @var list<AtomJob> */
    public array $dispatched = [];

    /** @var list<array{job: string, args: array<string, mixed>}> */
    public array $dispatchedJobs = [];

    /** @var list<array{channel: string, payload: array<string, mixed>}> */
    public array $broadcasts = [];

    /** @var array<string, \DateTimeImmutable> */
    public array $timers = [];

    /** @var list<string> */
    public array $timerCancellations = [];

    /**
     * @param array<string, mixed> $configValues
     */
    public function __construct(
        private readonly Database $database,
        private readonly object $appProxy,
        private readonly array $configValues = [],
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
        $this->dispatched[] = $job;
    }

    public function dispatchJob(string $job, array $args = []): void
    {
        $this->dispatchedJobs[] = ['job' => $job, 'args' => $args];
    }

    public function config(string $key): mixed
    {
        return $this->configValues[$key] ?? null;
    }

    public function broadcast(string $channel, array $payload): void
    {
        $this->broadcasts[] = ['channel' => $channel, 'payload' => $payload];
    }

    public function timers(): Timers
    {
        return $this;
    }

    public function schedule(string $name, \DateTimeImmutable $at): void
    {
        $this->timers[$name] = $at;
    }

    public function cancel(string $name): void
    {
        unset($this->timers[$name]);
        $this->timerCancellations[] = $name;
    }

    public function scheduledAt(string $name): ?\DateTimeImmutable
    {
        return $this->timers[$name] ?? null;
    }
}
