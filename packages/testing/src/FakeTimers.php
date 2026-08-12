<?php

declare(strict_types=1);

namespace Atoms\Testing;

use Atoms\Timers\Timers;

/**
 * In-memory {@see Timers} fake: `schedule()`/`cancel()`/`scheduledAt()` behave
 * exactly like the interface contract (schedule() replaces any existing timer
 * of the same name; cancel() is a no-op on an unknown name), plus an
 * inspection surface — `scheduled()` and `cancelled()` — for asserting on
 * what an Atom did, the same shape as {@see FakeConnection}'s `sent()`.
 */
final class FakeTimers implements Timers
{
    /** @var array<string, \DateTimeImmutable> */
    private array $scheduled = [];

    /** @var list<string> */
    private array $cancelled = [];

    public function schedule(string $name, \DateTimeImmutable $at): void
    {
        $this->scheduled[$name] = $at;
    }

    public function cancel(string $name): void
    {
        unset($this->scheduled[$name]);
        $this->cancelled[] = $name;
    }

    public function scheduledAt(string $name): ?\DateTimeImmutable
    {
        return $this->scheduled[$name] ?? null;
    }

    /**
     * @return array<string, \DateTimeImmutable> every timer currently pending, by name
     */
    public function scheduled(): array
    {
        return $this->scheduled;
    }

    /**
     * @return list<string> every name passed to cancel(), in order (including
     *     names that were never scheduled)
     */
    public function cancelled(): array
    {
        return $this->cancelled;
    }
}
