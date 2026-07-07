<?php

declare(strict_types=1);

namespace Atoms\Symfony\Messenger;

use Atoms\AtomJob;
use Atoms\Client\Callback\QueueBridge;
use Atoms\Serialization\Serializer;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * @internal Phase 1 layering test — not yet a supported product
 *
 * The Symfony-side implementation of atoms/client's QueueBridge: wraps a
 * dispatched AtomJob as an {@see AtomJobMessage} — normalized, JSON-safe
 * constructor arguments only, no object graphs on the bus — and hands it to
 * the app's own Messenger bus, so AtomJobs flow through whatever transports
 * the app already has configured (this is the Symfony analogue of the
 * Laravel adapter's ShouldQueue envelope, integration-plan §5.2).
 */
final class MessengerQueueBridge implements QueueBridge
{
    private readonly Serializer $serializer;

    public function __construct(
        private readonly MessageBusInterface $bus,
        ?Serializer $serializer = null,
    ) {
        $this->serializer = $serializer ?? new Serializer();
    }

    public function enqueue(AtomJob $job): void
    {
        $this->bus->dispatch(new AtomJobMessage($job::class, $this->normalizeConstructorArgs($job)));
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeConstructorArgs(AtomJob $job): array
    {
        $reflection = new \ReflectionClass($job);
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return [];
        }

        $args = [];
        foreach ($constructor->getParameters() as $param) {
            if (!$param->isPromoted()) {
                // AtomJob's contract (docs/conventions.md) requires promoted
                // properties; anything else does not survive the boundary.
                continue;
            }

            $property = $reflection->getProperty($param->getName());
            $args[$param->getName()] = $this->serializer->normalize($property->getValue($job));
        }

        return $args;
    }
}
