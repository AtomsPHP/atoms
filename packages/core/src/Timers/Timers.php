<?php

declare(strict_types=1);

namespace Atoms\Timers;

/**
 * Named one-shot timers scheduled against this Atom. `schedule()` with a name
 * that already has a pending timer replaces it — there is at most one
 * outstanding timer per name. A past `$at` fires as soon as possible rather
 * than being rejected. Delivery invokes the Atom's `onTimer($name)` hook
 * (via {@see \Atoms\Runtime\LifecycleInvoker}); timers are durable state, not
 * in-memory alarms, and survive hibernation between turns.
 */
interface Timers
{
    public function schedule(string $name, \DateTimeImmutable $at): void;

    public function cancel(string $name): void;

    public function scheduledAt(string $name): ?\DateTimeImmutable;
}
