<?php

declare(strict_types=1);

namespace Atoms\PHPStan\Tests\Fixtures\Clock;

use Atoms\AtomMethods;

/**
 * APP_SIDE Methods class. sleep() here runs in the customer's monolith, not
 * inside an Atom turn, so neither ATOMS-E101 nor ATOMS-E102 applies.
 * Asserted to produce zero errors from both AtomSleepCallRule and
 * AtomTimeWaitLoopRule.
 */
final class SleepMethods extends AtomMethods
{
    public function slowJob(): void
    {
        sleep(5);
    }
}
