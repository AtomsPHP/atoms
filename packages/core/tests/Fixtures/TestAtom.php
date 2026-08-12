<?php

declare(strict_types=1);

namespace Atoms\Core\Tests\Fixtures;

use Atoms\Atom;
use Atoms\AtomJob;
use Atoms\Database;
use Atoms\Timers\Timers;

/**
 * Exercises every protected accessor and lifecycle hook of the Atom base class.
 * The public `call*` wrappers let a test observe pass-through to the context.
 *
 * @extends Atom<TestMethods>
 */
final class TestAtom extends Atom
{
    public int $activations = 0;

    public int $deactivations = 0;

    /** @var list<string> */
    public array $timerFires = [];

    public function callDb(): Database
    {
        return $this->db();
    }

    public function callApp(): object
    {
        return $this->app();
    }

    public function callDispatch(AtomJob $job): void
    {
        $this->dispatch($job);
    }

    public function callConfig(string $key): mixed
    {
        return $this->config($key);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function callBroadcast(string $channel, array $payload): void
    {
        $this->broadcast($channel, $payload);
    }

    public function callTimers(): Timers
    {
        return $this->timers();
    }

    protected function onActivation(): void
    {
        $this->activations++;
    }

    protected function onDeactivation(): void
    {
        $this->deactivations++;
    }

    protected function onTimer(string $name): void
    {
        $this->timerFires[] = $name;
    }
}
