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

/**
 * Reconstructs an AtomJob from its {@see AtomJobMessage} wire envelope
 * (constructor arguments by name through the core Serializer, same algebra
 * the platform callback uses) and runs it: resolves `handle()` dependencies
 * from the container and invokes `handle()` when the job defines one.
 * Registered as a Messenger handler only when symfony/messenger is installed —
 * see MessengerBridgePass and AtomJobPass.
 */
#[AsMessageHandler]
final class AtomJobHandler
{
    private readonly Serializer $serializer;

    /**
     * @param ContainerInterface|null $container Either the per-job argument
     *                                           locator map compiled by
     *                                           {@see \Atoms\Symfony\DependencyInjection\AtomJobPass}
     *                                           (`jobClass => ContainerInterface`)
     *                                           or a flat PSR-11 container keyed
     *                                           by parameter type FQCN.
     */
    public function __construct(
        ?Serializer $serializer = null,
        private readonly ?ContainerInterface $container = null,
    ) {
        $this->serializer = $serializer ?? new Serializer();
    }

    public function __invoke(AtomJobMessage $message): void
    {
        $job = $this->reconstruct($message);

        if (!method_exists($job, 'handle')) {
            return;
        }

        $method = new \ReflectionMethod($job, 'handle');
        $args = $this->resolveHandleArguments($job::class, $method);

        $method->invokeArgs($job, $args);
    }

    /**
     * @param class-string<AtomJob> $jobClass
     *
     * @return list<mixed>
     */
    private function resolveHandleArguments(string $jobClass, \ReflectionMethod $method): array
    {
        $parameters = $method->getParameters();
        if ($parameters === []) {
            return [];
        }

        $jobLocator = null;
        if ($this->container !== null && $this->container->has($jobClass)) {
            $resolved = $this->container->get($jobClass);
            if ($resolved instanceof ContainerInterface) {
                $jobLocator = $resolved;
            }
        }

        $args = [];
        foreach ($parameters as $param) {
            $name = $param->getName();

            if ($jobLocator !== null && $jobLocator->has($name)) {
                $args[] = $jobLocator->get($name);

                continue;
            }

            if ($jobLocator === null && $this->container !== null) {
                $type = $param->getType();
                if ($type instanceof \ReflectionNamedType && !$type->isBuiltin() && $this->container->has($type->getName())) {
                    $args[] = $this->container->get($type->getName());

                    continue;
                }
            }

            if ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();

                continue;
            }

            if ($param->allowsNull()) {
                $args[] = null;

                continue;
            }

            $type = $param->getType();
            $typeLabel = $type !== null ? (string) $type : 'untyped';

            throw new \RuntimeException(sprintf(
                'Cannot resolve argument $%s (%s) for %s::handle(). Register %s as a Symfony service '
                . '(or list it under atoms.job_classes) and ensure %s is registered in the container.',
                $name,
                $typeLabel,
                $jobClass,
                $jobClass,
                $typeLabel,
            ));
        }

        return $args;
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
