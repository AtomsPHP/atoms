<?php

declare(strict_types=1);

namespace Atoms\Client;

/**
 * A stub proxy bound to one Atom ($type, $id). Every method call is forwarded to
 * {@see AtomsClient::call()} carrying the bound Atom class so the result can be
 * denormalized against the method's declared return type.
 */
final class AtomProxy
{
    /**
     * @param class-string $atomClass
     */
    public function __construct(
        private readonly AtomsClient $client,
        private readonly string $atomClass,
        private readonly string $type,
        private readonly string $id,
    ) {
    }

    /**
     * @param list<mixed> $arguments
     */
    public function __call(string $name, array $arguments): mixed
    {
        return $this->client->call($this->type, $this->id, $name, $arguments, $this->atomClass);
    }
}
