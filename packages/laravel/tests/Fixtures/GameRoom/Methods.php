<?php

declare(strict_types=1);

namespace Atoms\Laravel\Tests\Fixtures\GameRoom;

use Atoms\AtomMethods;

/**
 * Convention-resolved Methods class for the GameRoom fixture Atom
 * (Fixtures\GameRoom -> Fixtures\GameRoom\Methods), invoked by the callback
 * route end-to-end test.
 */
final class Methods extends AtomMethods
{
    public function add(int $a, int $b): int
    {
        return $a + $b;
    }

    /**
     * Deliberately throws, so callback route tests can exercise the
     * kernel's error-logging path (CallbackKernel::handleMethods()'s
     * catch (\Throwable) branch).
     */
    public function explode(): never
    {
        throw new \RuntimeException('boom');
    }
}
