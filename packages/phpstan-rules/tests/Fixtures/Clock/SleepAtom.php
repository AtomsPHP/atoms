<?php

declare(strict_types=1);

namespace Atoms\PHPStan\Tests\Fixtures\Clock;

use Atoms\Atom;

/**
 * WORLD_A Atom exercising every ATOMS-E101 sleep-family function. Asserted to
 * produce exactly four AtomSleepCallRule errors, one per call below. The
 * time() nested inside time_sleep_until()'s argument is not itself a
 * sleep-family call and must not add a fifth error.
 */
final class SleepAtom extends Atom
{
    public function bad(): void
    {
        sleep(1);
        usleep(100);
        time_nanosleep(0, 100);
        time_sleep_until(time() + 1);
    }
}
