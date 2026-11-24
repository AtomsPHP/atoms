<?php

declare(strict_types=1);

namespace Atoms\Symfony\Messenger;

use Atoms\AtomJob;
use Atoms\Errors\AtomsError;
use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;
use Atoms\Serialization\SerializationException;
use Atoms\Serialization\Serializer;
use Psr\Container\ContainerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class AtomJobHandler
{
    private readonly Serializer $serializer;

    public function __construct(
        ?Serializer $serializer = null,
        private readonly ?ContainerInterface $handleServices = null,
    ) {
        $this->serializer = $serializer ?? new Serializer();
    }

    public function __invoke(AtomJobMessage $message): void
    {
        $job = $this->reconstruct($message);

        if (method_exists($job, 'handle')) {
            $job->handle(...$this->handleArguments($job));
        }
    }

    /** @return array<string, mixed> */
    private function handleArguments(AtomJob $job): array
    {
        if ($this->handleServices === null) {
            return [];
        }

        $arguments = [];
        $handle = new \ReflectionMethod($job, 'handle');
        foreach ($handle->getParameters() as $parameter) {
            $type = $parameter->getType();
            if (!$type instanceof \ReflectionNamedType || $type->isBuiltin() || $parameter->isVariadic()) {
                continue;
            }

            $id = $type->getName();
            if (!$parameter->isOptional() || $this->handleServices->has($id)) {
                $arguments[$parameter->getName()] = $this->handleServices->get($id);
            }
        }

        return $arguments;
    }

    /**
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
