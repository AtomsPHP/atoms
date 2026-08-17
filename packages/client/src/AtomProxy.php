<?php

declare(strict_types=1);

namespace Atoms\Client;

/**
 * A stub proxy bound to one Atom ($type, $id). Every method call forwards to
 * {@see AtomsClient::call()}, carrying the bound Atom class so the result is
 * denormalized against the method's declared return type.
 *
 * **Declares `__construct`, `__call` and `__get`, and nothing else, permanently.**
 * A declared method wins over `__call()` in PHP, silently, so any other name
 * added here would shadow a customer Atom method of the same name with no error
 * at either end. Per-call configuration therefore arrives as a
 * {@see CallOptions} through {@see AtomsClient::get()}. See
 * docs/conventions.md §The proxy declares nothing.
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
        private readonly ?CallOptions $options = null,
    ) {
    }

    /**
     * @param list<mixed> $arguments
     */
    public function __call(string $name, array $arguments): mixed
    {
        return $this->client->call(
            $this->type,
            $this->id,
            $name,
            $arguments,
            $this->atomClass,
            $this->options->retryTurnDeadline ?? false,
            $this->options,
        );
    }

    /**
     * `AtomsClient::get()` is annotated `@return T`, so static analysis accepts
     * `Atoms::get(GameRoom::class, $id)->id` — but an Atom's properties live on the
     * platform and nothing was fetched. Without this PHP would return `null` with
     * a warning: a plausible, silently-wrong value.
     */
    public function __get(string $name): never
    {
        throw new \LogicException(sprintf(
            'Atoms: %s::$%s cannot be read through a proxy — an Atom\'s properties live on the platform, '
            . 'and reading one here would fetch nothing. Add a method that returns the value you want '
            . 'and call that instead.',
            $this->atomClass,
            $name,
        ));
    }
}
