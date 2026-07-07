<?php

declare(strict_types=1);

namespace Atoms\Core\Tests\Fixtures;

use Atoms\AtomJob;
use Atoms\Database;
use Atoms\Runtime\AtomContext;

/**
 * A recording {@see AtomContext} for exercising the Atom base class in isolation.
 */
final class StubAtomContext implements AtomContext
{
    /** @var list<AtomJob> */
    public array $dispatched = [];

    /** @var list<array{channel: string, payload: array<string, mixed>}> */
    public array $broadcasts = [];

    /**
     * @param array<string, mixed> $configValues
     */
    public function __construct(
        private readonly Database $database,
        private readonly object $appProxy,
        private readonly array $configValues = [],
    ) {
    }

    public function db(): Database
    {
        return $this->database;
    }

    public function app(): object
    {
        return $this->appProxy;
    }

    public function dispatch(AtomJob $job): void
    {
        $this->dispatched[] = $job;
    }

    public function config(string $key): mixed
    {
        return $this->configValues[$key] ?? null;
    }

    public function broadcast(string $channel, array $payload): void
    {
        $this->broadcasts[] = ['channel' => $channel, 'payload' => $payload];
    }
}
