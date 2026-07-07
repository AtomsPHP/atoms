<?php

declare(strict_types=1);

namespace Atoms\Symfony\Messenger;

/**
 * @internal Phase 1 layering test — not yet a supported product
 *
 * Wire envelope for an AtomJob dispatched through Symfony Messenger. Carries
 * the job's class name and its normalized (JSON-safe) constructor arguments —
 * never the object graph itself — so the message can safely cross Messenger
 * transports (Doctrine, AMQP, SQS, ...) exactly like the platform callback's
 * own `{"job": "FQCN", "args": {...}}` wire form (docs/conventions.md).
 */
final class AtomJobMessage
{
    /**
     * @param class-string       $jobClass
     * @param array<string, mixed> $args Normalized constructor arguments, keyed by parameter name.
     */
    public function __construct(
        public readonly string $jobClass,
        public readonly array $args,
    ) {
    }
}
