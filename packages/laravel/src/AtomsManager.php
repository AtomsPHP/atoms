<?php

declare(strict_types=1);

namespace Atoms\Laravel;

use Atoms\Client\AtomsClient;
use Atoms\Laravel\Testing\AtomsFake;

/**
 * The facade root behind {@see Facades\Atoms}. A thin dispatch layer over
 * {@see AtomsClient} that also knows how to switch itself into an in-memory
 * {@see AtomsFake} for tests — nothing here talks HTTP or touches the wire
 * format directly, that all lives in atoms/client.
 */
final class AtomsManager
{
    private ?AtomsFake $fake = null;

    public function __construct(private readonly AtomsClient $client)
    {
    }

    /**
     * Return a proxy bound to an Atom instance. $class may be the Atom's FQCN
     * or its wire type (basename); the fake honours either.
     *
     * @param class-string|string $class
     */
    public function get(string $class, string $id): object
    {
        return $this->fake?->get($class, $id) ?? $this->client->get($class, $id);
    }

    /**
     * @param list<mixed> $args
     */
    public function call(
        string $type,
        string $id,
        string $method,
        array $args = [],
        ?string $atomClass = null,
        bool $retryTurnDeadline = false,
    ): mixed {
        if ($this->fake !== null) {
            return $this->fake->get($atomClass ?? $type, $id)->{$method}(...$args);
        }

        return $this->client->call($type, $id, $method, $args, $atomClass, $retryTurnDeadline);
    }

    public function destroy(string $type, string $id): bool
    {
        return $this->fake?->destroy($type, $id) ?? $this->client->destroy($type, $id);
    }

    /**
     * Switch this manager into fake mode; no HTTP call will be made until the
     * test resets the container. Returns the fake so assertions can chain.
     *
     * @param array<string, array<string, mixed>> $stubs
     */
    public function fake(array $stubs = []): AtomsFake
    {
        return $this->fake = new AtomsFake($stubs);
    }

    public function isFake(): bool
    {
        return $this->fake !== null;
    }
}
