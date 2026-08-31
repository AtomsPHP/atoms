<?php

declare(strict_types=1);

namespace Atoms\PHPStan\Tests\Fixtures\Clock;

use Atoms\Atom;

/**
 * ATOM_SIDE Atom exercising every ATOMS-E101 sleep-family function. Asserted to
 * produce exactly five AtomSleepCallRule errors, one per call below. The
 * time() nested inside time_sleep_until()'s argument is not itself a
 * sleep-family call and must not add an extra error. fullyQualifiedStillFlags()
 * proves a fully qualified \sleep() is flagged even though — unlike an
 * unqualified call — it can never be shadowed by a namespace-local function;
 * see ShadowedSleepAtom for the unqualified-but-shadowed counterpart that
 * must NOT flag.
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

    public function fullyQualifiedStillFlags(): void
    {
        \sleep(2);
    }
}
