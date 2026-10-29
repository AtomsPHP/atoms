<?php

declare(strict_types=1);

namespace Atoms\Client\Tests\Support {
    /** Loaded only inside isolated callback expiry tests. */
    final class CallbackKernelClock
    {
        public static int $now = 1000;
    }
}

namespace Atoms\Client\Callback {
    function time(): int
    {
        return \Atoms\Client\Tests\Support\CallbackKernelClock::$now;
    }
}
