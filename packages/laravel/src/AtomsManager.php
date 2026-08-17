<?php

declare(strict_types=1);

namespace Atoms\Laravel;

use Atoms\Client\AtomsClient;
use Atoms\Client\CallOptions;
use Atoms\Client\Tickets\Ticket;
use Atoms\Client\Tickets\TicketIssuer;
use Atoms\Laravel\Testing\AtomsFake;

/**
 * The facade root behind {@see Facades\Atoms}: a thin dispatch layer over
 * {@see AtomsClient} that can switch itself into an in-memory {@see AtomsFake}
 * for tests. Nothing here touches the wire format; that lives in atoms/client.
 */
final class AtomsManager
{
    private ?AtomsFake $fake = null;

    /**
     * $tickets is optional so constructing a manager directly (tests, third-party
     * code) keeps working; the service provider always supplies it.
     */
    public function __construct(
        private readonly AtomsClient $client,
        private readonly ?TicketIssuer $tickets = null,
    ) {
    }

    /**
     * Return a proxy bound to an Atom instance.
     *
     * @template T of object
     *
     * @param class-string<T> $class the Atom's FQCN for static analysis; a bare
     *                               wire type also works at runtime and in the fake
     *
     * @return T
     */
    public function get(string $class, string $id, ?CallOptions $options = null): object
    {
        /** @var T $proxy */
        $proxy = $this->fake?->get($class, $id) ?? $this->client->get($class, $id, $options);

        return $proxy;
    }

    /**
     * Issue a WebSocket connection ticket for one Atom. Sugar over
     * {@see TicketIssuer::issue()} that takes the FQCN instead of repeating the
     * basename as a string next to `GameRoom::class`.
     *
     * @param class-string|string   $class  the Atom's FQCN, or its wire type
     * @param array<string, string> $claims merged over the browser's query params on connect, server wins
     *
     * @throws \Atoms\Client\Exception\InvalidTicketClaims when the scope or claims do not fit the protocol (ATOMS-E068)
     */
    public function ticket(string $class, string $id, array $claims = [], ?int $ttlMs = null): Ticket
    {
        $type = AtomsClient::wireType($class);

        if ($this->fake !== null) {
            return $this->fake->ticket($type, $id, $claims);
        }

        if ($this->tickets === null) {
            throw new \LogicException(
                'Atoms: no ' . TicketIssuer::class . ' is bound, so a ticket cannot be issued. '
                . 'AtomsServiceProvider registers one; bind it yourself if you constructed AtomsManager by hand.',
            );
        }

        return $this->tickets->issue($type, $id, $claims, $ttlMs);
    }

    /**
     * The WebSocket URL for one Atom. Pass a ticket in `$query['ticket']`.
     *
     * Always built from the real client, even under {@see self::fake()}: there is
     * no request to intercept, and a test asserting the URL a view renders wants
     * the real one.
     *
     * @param class-string                                      $class
     * @param array<string, string|int|float|bool|list<string>> $query
     */
    public function wsUrl(string $class, string $id, array $query = []): string
    {
        return $this->client->wsUrl($class, $id, $query);
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
        ?CallOptions $options = null,
    ): mixed {
        if ($this->fake !== null) {
            return $this->fake->get($atomClass ?? $type, $id)->{$method}(...$args);
        }

        return $this->client->call($type, $id, $method, $args, $atomClass, $retryTurnDeadline, $options);
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
