<?php

declare(strict_types=1);

namespace Atoms\Symfony\Messenger;

use Atoms\AtomJob;
use Atoms\Errors\AtomsError;
use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;
use Atoms\Serialization\SerializationException;
use Atoms\Serialization\Serializer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Reconstructs an AtomJob from its {@see AtomJobMessage} wire envelope
 * (constructor arguments by name through the core Serializer, same algebra
 * the platform callback uses) and runs it: calls `handle()` when the job
 * defines one. Registered as a Messenger handler only when symfony/messenger
 * is installed — see MessengerBridgePass.
 */
#[AsMessageHandler]
final class AtomJobHandler
{
    private readonly Serializer $serializer;

    public function __construct(?Serializer $serializer = null)
    {
        $this->serializer = $serializer ?? new Serializer();
    }

    public function __invoke(AtomJobMessage $message): void
    {
        $job = $this->reconstruct($message);

        if (method_exists($job, 'handle')) {
            $job->handle();
        }
    }

    /**
     * The binding algebra lives in the core Serializer (docs/conventions.md),
     * so a Messenger-delivered job hydrates exactly as the callback kernel's
     * does — an absent argument takes its default, then null when the
     * parameter is nullable, and is a catalogued failure otherwise.
     *
     * @throws AtomsError            ATOMS-E033 when the class is not an AtomJob
     * @throws SerializationException ATOMS-E024 when an argument is missing or
     *                                of the wrong type
     */
    private function reconstruct(AtomJobMessage $message): AtomJob
    {
        $class = $message->jobClass;

        if (!class_exists($class) || !is_subclass_of($class, AtomJob::class)) {
            throw new AtomsError(ErrorCode::NotAnAtomJob, ErrorCatalog::format(
                ErrorCode::NotAnAtomJob,
                ['atom' => 'The Messenger envelope', 'class' => $class === '' ? '(none)' : $class],
            ));
        }

        /** @var \ReflectionClass<AtomJob> $reflection */
        $reflection = new \ReflectionClass($class);

        return $reflection->newInstanceArgs(
            $this->serializer->denormalizeNamedArguments($class, $message->args),
        );
    }
}
