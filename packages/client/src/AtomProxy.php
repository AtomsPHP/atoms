<?php

declare(strict_types=1);

namespace Atoms\Client;

/**
 * A stub proxy bound to one Atom ($type, $id). Every method call is forwarded to
 * {@see AtomsClient::call()} carrying the bound Atom class so the result can be
 * denormalized against the method's declared return type.
 *
 * **This class declares `__construct`, `__call` and `__get`, and nothing else,
 * permanently.** Every other name on it belongs to the Atom. A declared method
 * wins over `__call()` in PHP, silently, so adding one here would make a
 * customer Atom method of that name unreachable — the wrong code would run, with
 * no error at either end. Per-call configuration therefore arrives as a
 * {@see CallOptions} through {@see AtomsClient::get()} instead of as fluent
 * methods here. See docs/conventions.md §The proxy declares nothing.
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
     * Reading a property off a proxy is always a mistake, and this makes it a
     * loud one.
     *
     * `AtomsClient::get()` is annotated `@return T`, so static analysis will
     * happily accept `Atoms::get(GameRoom::class, $id)->id` — `Atom` really does
     * declare `public readonly string $id`. But an Atom's properties live on the
     * platform; nothing was fetched here. Without this, PHP would answer with a
     * warning and `null`, which is the worst possible outcome: a plausible value
     * that is silently wrong.
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
