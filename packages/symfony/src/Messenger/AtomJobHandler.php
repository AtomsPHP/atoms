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

    /** @param array<class-string<AtomJob>, iterable<string, mixed>> $jobArguments */
    public function __construct(
        ?Serializer $serializer = null,
        private readonly ?ContainerInterface $handleServices = null,
        private readonly array $jobArguments = [],
    ) {
        $this->serializer = $serializer ?? new Serializer();
    }

    public function __invoke(AtomJobMessage $message): void
    {
        $job = $this->reconstruct($message);

        if (method_exists($job, 'handle')) {
            $job->handle(...($this->jobArguments[$job::class] ?? $this->handleArguments($job)));
        }
    }

    /** @return array<string, mixed> */
    private function handleArguments(AtomJob $job): array
    {
        $arguments = [];
        $handle = new \ReflectionMethod($job, 'handle');
        foreach ($handle->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($parameter->isVariadic()) {
                continue;
            }

            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin() && $this->handleServices?->has($type->getName())) {
                $arguments[$parameter->name] = $this->handleServices->get($type->getName());
            } elseif (!$parameter->isDefaultValueAvailable() && $parameter->allowsNull()) {
                $arguments[$parameter->name] = null;
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
