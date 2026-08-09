<?php

declare(strict_types=1);

namespace Atoms\Laravel\Testing;

/**
 * Stub proxy handed out by {@see AtomsFake::get()}. Every call is recorded and
 * answered from the fake's stubs, with no serialization or network involved.
 */
final class FakeAtomProxy
{
    public function __construct(
        private readonly AtomsFake $fake,
        private readonly string $type,
        private readonly string $id,
    ) {
    }

    /**
     * @param list<mixed> $arguments
     */
    public function __call(string $name, array $arguments): mixed
    {
        return $this->fake->record($this->type, $this->id, $name, $arguments);
    }
}
