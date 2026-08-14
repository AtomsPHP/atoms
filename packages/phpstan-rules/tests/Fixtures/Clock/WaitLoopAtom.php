<?php

declare(strict_types=1);

namespace Atoms\PHPStan\Tests\Fixtures\Clock;

use Atoms\Atom;

/**
 * WORLD_A Atom exercising every ATOMS-E102 wait-loop shape. Asserted to
 * produce exactly five AtomTimeWaitLoopRule errors, one per loop below.
 * waitsOnFullyQualifiedCondition() proves a fully qualified \time() is
 * flagged even though — unlike an unqualified call — it can never be
 * shadowed by a namespace-local function; see ShadowedWaitLoopAtom for the
 * unqualified-but-shadowed counterpart that must NOT flag.
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

    public function waitsOnFullyQualifiedCondition(int $deadline): void
    {
        while (\time() < $deadline) {
            $noop = 1;
        }
    }
}
