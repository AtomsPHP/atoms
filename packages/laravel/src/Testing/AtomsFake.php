<?php

declare(strict_types=1);

namespace Atoms\Laravel\Testing;

use Atoms\Client\Tickets\Ticket;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * In-memory stand-in for {@see \Atoms\Client\AtomsClient} installed by
 * {@see \Atoms\Laravel\AtomsManager::fake()}. No HTTP call is ever made: every
 * proxy method call is recorded and answered from a stub table keyed by
 * (wire type, method).
 */
final class AtomsFake
{
    /** @var array<string, array<string, mixed>> type => method => value|closure */
    private array $stubs = [];

    /** @var list<array{type: string, id: string, method: string, args: list<mixed>}> */
    private array $invocations = [];

    /** @var list<array{type: string, id: string}> */
    private array $destroyedAtoms = [];

    /** @var array<string, Ticket|\Closure> type => ticket|closure */
    private array $ticketStubs = [];

    /** @var list<array{type: string, id: string, claims: array<string, string>}> */
    private array $mintedTickets = [];

    /**
     * @param array<string, array<string, mixed>> $stubs type => [method => value|closure]
     */
    public function __construct(array $stubs = [])
    {
        foreach ($stubs as $atomClassOrType => $methods) {
            foreach ($methods as $method => $returnOrClosure) {
                $this->stub($atomClassOrType, (string) $method, $returnOrClosure);
            }
        }
    }

    /**
     * Register (or replace) the stubbed response for one Atom-type/method
     * pair. $returnOrClosure may be a plain value, or a closure receiving the
     * call's positional arguments and returning the stubbed result.
     */
    public function stub(string $atomClassOrType, string $method, mixed $returnOrClosure): self
    {
        $this->stubs[self::typeOf($atomClassOrType)][$method] = $returnOrClosure;

        return $this;
    }

    public function get(string $atomClassOrType, string $id): FakeAtomProxy
    {
        return new FakeAtomProxy($this, self::typeOf($atomClassOrType), $id);
    }

    public function destroy(string $atomClassOrType, string $id): bool
    {
        $this->destroyedAtoms[] = ['type' => self::typeOf($atomClassOrType), 'id' => $id];

        return true;
    }

    /**
     * Called by {@see FakeAtomProxy}: records the invocation and resolves the
     * stubbed response, or null when nothing was stubbed for it.
     *
     * @param list<mixed> $args
     */
    public function record(string $type, string $id, string $method, array $args): mixed
    {
        $this->invocations[] = ['type' => $type, 'id' => $id, 'method' => $method, 'args' => $args];

        $stub = $this->stubs[$type][$method] ?? null;

        return $stub instanceof \Closure ? $stub(...$args) : $stub;
    }

    /**
     * Register the {@see Ticket} this fake answers with for one Atom type.
     * $ticketOrClosure may be a Ticket, or a closure receiving ($id, $claims).
     *
     * Unstubbed types still get a syntactically plausible ticket, because most
     * tests care that a ticket was issued for the right scope and claims, not
     * what its bytes are.
     */
    public function stubTicket(string $atomClassOrType, Ticket|\Closure $ticketOrClosure): self
    {
        $this->ticketStubs[self::typeOf($atomClassOrType)] = $ticketOrClosure;

        return $this;
    }

    /**
     * Called by {@see \Atoms\Laravel\AtomsManager::ticket()} in fake mode.
     *
     * @param array<string, string> $claims
     */
    public function ticket(string $atomClassOrType, string $id, array $claims = []): Ticket
    {
        $type = self::typeOf($atomClassOrType);

        $this->mintedTickets[] = ['type' => $type, 'id' => $id, 'claims' => $claims];

        $stub = $this->ticketStubs[$type] ?? null;

        if ($stub instanceof \Closure) {
            return $stub($id, $claims);
        }

        // A real issuer signs; this one only has to be distinguishable and
        // shaped like the real thing. The expiry is a minute out so any code
        // comparing it against "now" behaves as it would in production.
        return $stub ?? new Ticket(
            sprintf('v1.fake-%s-%s.fake-signature', $type, $id),
            (time() + 60) * 1000,
        );
    }

    /**
     * @param callable(array<string, string>): bool|null $filter receives the claims
     */
    public function assertTicketIssued(string $atomClassOrType, string $id, ?callable $filter = null): void
    {
        $type = self::typeOf($atomClassOrType);
        $found = false;

        foreach ($this->mintedTickets as $minted) {
            if ($minted['type'] !== $type || $minted['id'] !== $id) {
                continue;
            }

            if ($filter === null || $filter($minted['claims'])) {
                $found = true;

                break;
            }
        }

        PHPUnit::assertTrue($found, "Expected a ticket to have been issued for Atom {$type}:{$id}, but none was.");
    }

    /**
     * @return list<array{type: string, id: string, claims: array<string, string>}>
     */
    public function issuedTickets(): array
    {
        return $this->mintedTickets;
    }

    public function assertInvoked(string $atomClassOrType, string $method, ?callable $filter = null): void
    {
        PHPUnit::assertTrue(
            $this->wasInvoked($atomClassOrType, $method, $filter),
            "Expected {$method}() to have been invoked on Atom type '" . self::typeOf($atomClassOrType) . "', but it was not.",
        );
    }

    public function assertNotInvoked(string $atomClassOrType, string $method, ?callable $filter = null): void
    {
        PHPUnit::assertFalse(
            $this->wasInvoked($atomClassOrType, $method, $filter),
            "Expected {$method}() NOT to have been invoked on Atom type '" . self::typeOf($atomClassOrType) . "', but it was.",
        );
    }

    public function assertDestroyed(string $atomClassOrType, string $id): void
    {
        $type = self::typeOf($atomClassOrType);

        $found = array_filter(
            $this->destroyedAtoms,
            static fn (array $entry): bool => $entry['type'] === $type && $entry['id'] === $id,
        );

        PHPUnit::assertNotEmpty($found, "Expected Atom {$type}:{$id} to have been destroyed, but it was not.");
    }

    /**
     * @return list<array{type: string, id: string, method: string, args: list<mixed>}>
     */
    public function invocations(): array
    {
        return $this->invocations;
    }

    private function wasInvoked(string $atomClassOrType, string $method, ?callable $filter): bool
    {
        $type = self::typeOf($atomClassOrType);

        foreach ($this->invocations as $invocation) {
            if ($invocation['type'] !== $type || $invocation['method'] !== $method) {
                continue;
            }

            if ($filter === null || $filter(...$invocation['args'])) {
                return true;
            }
        }

        return false;
    }

    private static function typeOf(string $atomClassOrType): string
    {
        return class_exists($atomClassOrType) ? self::basename($atomClassOrType) : $atomClassOrType;
    }

    private static function basename(string $class): string
    {
        $pos = strrpos($class, '\\');

        return $pos === false ? $class : substr($class, $pos + 1);
    }
}
