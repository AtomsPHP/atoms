<?php

declare(strict_types=1);

namespace Atoms\Symfony\DependencyInjection;

use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class ServiceDiscovery
{
    /**
     * @template T of object
     * @param class-string<T> $base
     * @return array<class-string<T>, string> Concrete class to service id.
     */
    public static function find(ContainerBuilder $container, string $tag, string $base): array
    {
        $services = [];
        foreach ($container->getDefinitions() as $id => $definition) {
            // Autoconfiguration runs before Symfony resolves inherited service classes.
            $inheritsClass = $definition instanceof ChildDefinition && $definition->getClass() === null && $definition->isAutoconfigured();
            if (!$definition->hasTag($tag) && !$inheritsClass) {
                continue;
            }
            if ($definition->isAbstract() || $definition->isSynthetic() || $definition->hasErrors()
                || $definition->hasTag('container.excluded') || $definition->getDecoratedService() !== null) {
                continue;
            }
            $class = self::serviceClass($container, $definition);
            $reflection = $container->getReflectionClass($class, false);
            if ($reflection === null || $reflection->isAbstract() || !$reflection->isSubclassOf($base)) {
                continue;
            }
            $class = $reflection->getName();
            if ($id === $class || !isset($services[$class])) {
                $services[$class] = $container->hasAlias($class) ? $class : $id;
            }
        }

        return $services;
    }

    private static function serviceClass(ContainerBuilder $container, Definition $definition): ?string
    {
        $seen = [];
        while ($definition instanceof ChildDefinition && $definition->getClass() === null) {
            $parent = $definition->getParent();
            if (isset($seen[$parent]) || !$container->has($parent)) {
                return null;
            }
            $seen[$parent] = true;
            $definition = $container->findDefinition($parent);
        }

        return $definition->getClass();
    }
}
