<?php

declare(strict_types=1);

namespace Atoms\PHPStan\Tests\Fixtures\Clock;

use Atoms\Atom;

/**
 * WORLD_A Atom exercising every ATOMS-E102 wait-loop shape. Asserted to
 * produce exactly four AtomTimeWaitLoopRule errors, one per loop below.
 */
final class WaitLoopAtom extends Atom
{
    public function waitsOnWhileCondition(int $deadline): void
    {
        while (time() < $deadline) {
            $noop = 1;
        }
    }

    public function waitsOnDoWhileCondition(int $deadline): void
    {
        do {
            $noop = 1;
        } while (microtime(true) < $deadline);
    }

    public function waitsOnForCondition(int $deadline): void
    {
        for ($i = 0; hrtime(true) < $deadline; $i++) {
            $noop = $i;
        }
    }

    public function waitsUnconditionallyOnBodyRead(int $deadline): void
    {
        while (true) {
            if (time() > $deadline) {
                break;
            }
        }
    }
}
