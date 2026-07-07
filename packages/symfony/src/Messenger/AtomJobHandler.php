<?php

declare(strict_types=1);

namespace Atoms\Symfony\Messenger;

use Atoms\AtomJob;
use Atoms\Serialization\Serializer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * @internal Phase 1 layering test — not yet a supported product
 *
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

    private function reconstruct(AtomJobMessage $message): AtomJob
    {
        $class = $message->jobClass;

        if (!class_exists($class) || !is_subclass_of($class, AtomJob::class)) {
            throw new \RuntimeException(sprintf(
                'Cannot reconstruct AtomJob: "%s" is not a class extending %s.',
                $class,
                AtomJob::class,
            ));
        }

        /** @var \ReflectionClass<AtomJob> $reflection */
        $reflection = new \ReflectionClass($class);
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return $reflection->newInstance();
        }

        $args = [];
        foreach ($constructor->getParameters() as $param) {
            $name = $param->getName();

            if (array_key_exists($name, $message->args)) {
                $type = $this->parameterType($param);
                $args[] = $type === 'mixed' ? $message->args[$name] : $this->serializer->denormalize($message->args[$name], $type);
                continue;
            }

            if ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
                continue;
            }

            $args[] = null;
        }

        return $reflection->newInstanceArgs($args);
    }

    private function parameterType(\ReflectionParameter $param): string
    {
        $type = $param->getType();

        if (!$type instanceof \ReflectionNamedType) {
            return 'mixed';
        }

        $name = $type->getName();

        if ($name === 'mixed') {
            return 'mixed';
        }

        return ($type->allowsNull() && $name !== 'null') ? '?' . $name : $name;
    }
}
