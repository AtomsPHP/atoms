<?php

declare(strict_types=1);

namespace App\Atoms;

use Atoms\Atom;

/**
 * Scheduler — a fixture Atom for Durable Object alarm conformance testing
 * (conformance checks 23/24). Customer-style code on the frozen
 * ABI only: everything here goes through $this->timers(), never a host
 * internal (the one exception, readReservedTimers(), exists specifically to
 * prove a host internal is unreachable).
 */
final class Scheduler extends Atom
{
    /**
     * Schedule a named timer $delayMs from now.
     *
     * The guest clock is turn-start time, not wall-clock-at-call-time (see
     * Counter::slowIncrement()'s comment on the frozen-clock behaviour
     * measured on deployed workerd) — fine here, since "N ms from when I was
     * asked to schedule it" is exactly what a customer means by this call.
     * `createFromFormat('U.u', ...)` needs microsecond precision in its
     * fractional part, hence the %.6f.
     */
    public function arm(string $name, int $delayMs): void
    {
        $at = \DateTimeImmutable::createFromFormat(
            'U.u',
            sprintf('%.6f', microtime(true) + $delayMs / 1000)
        );

        $this->timers()->schedule($name, $at);
    }

    /**
     * Schedule, then throw from inside an open transaction — the schedule
     * must roll back with everything else the transaction wrote (conformance
     * check 23: timer.schedule is transactional). Deliberately left
     * uncaught: the transaction's rollback-and-rethrow is what turns this
     * into an atom_exception turn result.
     */
    public function armInsideRollback(string $name, int $delayMs): void
    {
        $this->db()->transaction(function () use ($name, $delayMs): void {
            $this->arm($name, $delayMs);

            throw new \RuntimeException('armInsideRollback: deliberate failure after an observed schedule');
        });
    }

    public function cancelTimer(string $name): void
    {
        $this->timers()->cancel($name);
    }

    /**
     * @return int|null epoch milliseconds, or null if $name has no pending timer
     */
    public function scheduledMs(string $name): ?int
    {
        $at = $this->timers()->scheduledAt($name);

        return $at === null ? null : (int) $at->format('Uv');
    }

    /**
     * @return list<array{name: string, fired_at: string}>
     */
    public function timerLog(): array
    {
        $rows = $this->db()->query('SELECT name, fired_at FROM scheduler_events ORDER BY id ASC');

        return array_map(
            static fn (array $r): array => ['name' => (string) $r['name'], 'fired_at' => (string) $r['fired_at']],
            $rows
        );
    }

    /**
     * A probe for conformance check 23's reserved-table assertion, mirroring
     * Counter::readReserved(): customer SQL must never reach __atoms_timers.
     *
     * @return array{rejected: bool, message: string}
     */
    public function readReservedTimers(): array
    {
        try {
            $this->db()->query('SELECT name FROM __atoms_timers');
        } catch (\PDOException $e) {
            return ['rejected' => true, 'message' => $e->getMessage()];
        }

        return ['rejected' => false, 'message' => ''];
    }

    /**
     * The one lifecycle hook the Cloudflare runtime dispatches from an
     * alarm, never from `/invoke`. bootstrap.php's invocable_method() rejects
     * it BY NAME, against the canonical name reflection reports — the same
     * denylist onConnect/onMessage/onDisconnect are on. Visibility is not the
     * guard: `protected` is the subclass's to widen, and a customer who made
     * onTimer public would otherwise have published it to every client.
     *
     * A name starting with "boom" throws, uncaught, so conformance check 23
     * can prove a throwing onTimer is still consumed at-most-once and leaves
     * the residency healthy. "chain-1" reschedules "chain-2" immediately,
     * proving that scheduling from inside onTimer itself works.
     */
    protected function onTimer(string $name): void
    {
        if (str_starts_with($name, 'boom')) {
            throw new \RuntimeException('boom');
        }

        $this->db()->execute(
            'INSERT INTO scheduler_events (name, fired_at) VALUES (?, ?)',
            [$name, date('Y-m-d H:i:s')]
        );

        if ($name === 'chain-1') {
            $this->timers()->schedule('chain-2', new \DateTimeImmutable());
        }
    }
}
