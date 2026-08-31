<?php

declare(strict_types=1);

namespace Atoms\PHPStan\Tests\Fixtures\Clock;

use Atoms\Atom;

/**
 * ATOM_SIDE Atom exercising every legal clock shape: a bounded loop that reads
 * the clock in its body, a private method that shadows the name "sleep",
 * an assignment-condition while loop, an unconditional loop whose body never
 * reads the clock, and a clock read entirely outside any loop. Asserted to
 * produce zero errors from both AtomSleepCallRule and AtomTimeWaitLoopRule.
 */
final class CleanClockAtom extends Atom
{
    public function boundedLoopReadsClockInBody(): void
    {
        for ($i = 0; $i < 10; $i++) {
            time();
        }
    }

    public function callsOwnSleepMethod(): void
    {
        $this->sleep();
    }

    /**
     * @param list<int> $stack
     */
    public function drainsStack(array &$stack): void
    {
        while ($x = array_pop($stack)) {
            unset($x);
        }
    }

    public function unconditionalLoopWithoutClockRead(): void
    {
        $queue = [1, 2, 3];

        while (true) {
            if (empty($queue)) {
                break;
            }

            array_pop($queue);
        }
    }

    public function readsClockOutsideAnyLoop(): void
    {
        $moment = new \DateTimeImmutable();
        unset($moment);
    }

    private function sleep(): void
    {
        // Not the global sleep() — a private method, never flagged.
    }
}
