<?php

declare(strict_types=1);

namespace Atoms\Symfony\DependencyInjection;

use Atoms\AtomJob;
use Atoms\Errors\AtomsError;
use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;
use Atoms\Symfony\Messenger\AtomJobHandler;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\TypedReference;
use Symfony\Component\VarExporter\ProxyHelper;

final class AtomJobPass implements CompilerPassInterface
{
    public const TAG = 'atoms.job';
    public const CONFIG_PARAMETER = 'atoms.job_classes';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(AtomJobHandler::class) || !$container->hasParameter(self::CONFIG_PARAMETER)) {
            return;
        }

        $discovered = ServiceDiscovery::find($container, self::TAG, AtomJob::class);
        /** @var list<class-string> $configured */
        $configured = $container->getParameter(self::CONFIG_PARAMETER);
        $jobs = [];
        foreach (array_unique([...array_keys($discovered), ...$configured]) as $class) {
            $reflection = $container->getReflectionClass($class, false);
            if ($reflection === null || !$reflection->isSubclassOf(AtomJob::class) || $reflection->isAbstract()) {
                throw new AtomsError(ErrorCode::NotAnAtomJob, ErrorCatalog::format(
                    ErrorCode::NotAnAtomJob, ['atom' => 'atoms.job_classes', 'class' => $class],
                ));
            }
            if ($reflection->hasMethod('handle')) {
                // Symfony resolves these references when the handler iterates this job's arguments.
                $jobs[$reflection->getName()] = new IteratorArgument($this->arguments($reflection->getMethod('handle')));
            }
        }
        $container->getDefinition(AtomJobHandler::class)->setArgument('$jobArguments', $jobs);
    }

    /** @return array<string, TypedReference|null> */
    private function arguments(\ReflectionMethod $handle): array
    {
        $arguments = [];
        foreach ($handle->getParameters() as $parameter) {
            if ($parameter->isVariadic()) {
                continue;
            }

            $type = ProxyHelper::exportType($parameter, true);
            $autowire = $parameter->getAttributes(Autowire::class, \ReflectionAttribute::IS_INSTANCEOF)[0] ?? null;
            if (!$type && $autowire === null) {
                if ($parameter->allowsNull() && !$parameter->isDefaultValueAvailable()) {
                    $arguments[$parameter->name] = null;
                }
                continue;
            }

            $type = $type ?: (string) $parameter->getType();
            $target = null;
            $name = Target::parseName($parameter, $target);
            $attributes = $target === null ? [] : [$target];
            if ($autowire !== null) {
                $attributes[] = $autowire->newInstance();
            }
            $behavior = match (true) {
                $parameter->isDefaultValueAvailable() => ContainerInterface::IGNORE_ON_INVALID_REFERENCE,
                $parameter->allowsNull() => ContainerInterface::NULL_ON_INVALID_REFERENCE,
                default => ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE,
            };
            $arguments[$parameter->name] = new TypedReference($type, $type, $behavior, $name, $attributes);
        }

        return $arguments;
    }
}
