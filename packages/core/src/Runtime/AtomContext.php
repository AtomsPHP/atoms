<?php

declare(strict_types=1);

namespace Atoms\Runtime;

use Atoms\AtomJob;
use Atoms\Database;

/**
 * Everything an Atom needs from its host runtime, behind one interface. The
 * platform runtime and the test harness each provide an implementation; the
 * {@see \Atoms\Atom} base class delegates its protected accessors here.
 */
interface AtomContext
{
    public function db(): Database;

    /**
     * The `app()` proxy — reverse RPC into the monolith's Methods class.
     */
    public function app(): object;

    public function dispatch(AtomJob $job): void;

    public function config(string $key): mixed;

    /**
     * @param array<string, mixed> $payload
     */
    public function broadcast(string $channel, array $payload): void;
}
