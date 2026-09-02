<?php

/**
 * `Atoms\Timers\Timers` for the Cloudflare runtime: named one-shot timers backed
 * by the host's `__atoms_timers` table and a single multiplexed Durable
 * Object alarm. `schedule()`/`cancel()`/`scheduledAt()` each
 * cross exactly one sync op ('!' door) — none of this parks, so it works
 * identically from an invoke turn, a ws turn, or a timer turn itself (a
 * chained reschedule from inside `onTimer()` — see the fixture's Scheduler).
 *
 * `due_at_ms` is a plain JSON number, not int64-tagged: it is milliseconds
 * since the epoch, always far inside 2^53 for any timer scheduled from now
 * (runtime-spec.md's int64 rule only taxes values that actually need it).
 *
 * No declare(strict_types=1) — see the note in host.php.
 */

namespace Atoms\Cf;

use Atoms\Timers\Timers;

final class CfTimers implements Timers
{
    /** @var array{type: string, id: string} */
    private $identity;

    /** @param array{type: string, id: string} $identity */
    public function __construct(array $identity)
    {
        $this->identity = $identity;
    }

    public function schedule(string $name, \DateTimeImmutable $at): void
    {
        // format('U') is always epoch seconds regardless of $at's timezone,
        // and format('v') is the millisecond component, zero-padded to 3
        // digits — concatenating the two strings is textually identical to
        // the decimal rendering of epoch milliseconds, so the (int) cast is
        // exact.
        $dueAtMs = (int) $at->format('Uv');

        $reply = host_sync_raw(['op' => 'timer.schedule', 'name' => $name, 'due_at_ms' => $dueAtMs]);

        if (!is_array($reply) || $reply['ok'] !== true) {
            $error = isset($reply['error']) && is_array($reply['error']) ? $reply['error'] : [];
            $code = isset($error['code']) ? (string) $error['code'] : 'unknown';

            if ($code === 'timer_invalid_name') {
                throw InvalidTimerName::create($name);
            }
            if ($code === 'timer_limit') {
                $count = isset($error['count']) ? (int) $error['count'] : 0;

                throw TimerLimitExceeded::create($this->identity, $count);
            }

            throw new \RuntimeException(sprintf(
                'Atoms: timer.schedule failed for %s: %s',
                $name,
                isset($error['message']) ? (string) $error['message'] : 'unknown error'
            ));
        }
    }

    public function cancel(string $name): void
    {
        // Idempotent on the host side: cancelling a name with no pending
        // timer is a defined no-op success, so a plain host_sync() (throws
        // on ok:false) never fires for an ordinary call.
        host_sync(['op' => 'timer.cancel', 'name' => $name]);
    }

    public function scheduledAt(string $name): ?\DateTimeImmutable
    {
        $reply = host_sync(['op' => 'timer.get', 'name' => $name]);

        if (!array_key_exists('due_at_ms', $reply) || $reply['due_at_ms'] === null) {
            return null;
        }

        $dueAtMs = (int) $reply['due_at_ms'];
        $seconds = intdiv($dueAtMs, 1000);
        $micros = ($dueAtMs % 1000) * 1000;

        $at = \DateTimeImmutable::createFromFormat(
            'U.u',
            sprintf('%d.%06d', $seconds, $micros),
            new \DateTimeZone('UTC')
        );

        if ($at === false) {
            throw new \RuntimeException(sprintf(
                'Atoms: could not reconstruct a DateTimeImmutable from due_at_ms=%d for timer %s.',
                $dueAtMs,
                $name
            ));
        }

        return $at;
    }
}
