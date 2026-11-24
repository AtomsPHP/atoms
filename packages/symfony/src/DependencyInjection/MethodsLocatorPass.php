<?php

declare(strict_types=1);

namespace Atoms\Symfony\DependencyInjection;

use Atoms\Client\Callback\MethodsResolver;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class MethodsLocatorPass implements CompilerPassInterface
{
    public const TAG = 'atoms.methods';
    public const CONFIG_PARAMETER = 'atoms.methods_classes';

    public function process(ContainerBuilder $container): void
    {
        $methods = [];
        foreach ($container->findTaggedServiceIds(self::TAG) as $id => $_) {
            $definition = $container->getDefinition($id);
            $class = $container->getParameterBag()->resolveValue($definition->getClass());
            if ($definition->isAbstract() || !is_string($class)) {
                continue;
            }
            if ($id === $class || !isset($methods[$class])) {
                $methods[$class] = new Reference($container->hasAlias($class) ? $class : $id);
            }
        }

        /** @var list<class-string> $configured */
        $configured = $container->getParameter(self::CONFIG_PARAMETER);
        foreach ($configured as $class) {
            if (isset($methods[$class])) {
                continue;
            }
            $excluded = $container->hasDefinition($class) && $container->getDefinition($class)->hasTag('container.excluded');
            if ($excluded || !$container->has($class)) {
                $container->register($class, $class)->setAutowired(true);
            }
            $methods[$class] = new Reference($class);
        }

        // The Worker sends the Atom's short name in callbacks.
        $types = [];
        foreach (array_keys($methods) as $class) {
            if (str_ends_with($class, '\\Methods')) {
                $atom = substr($class, 0, -strlen('\\Methods'));
                $reflection = $container->getReflectionClass($atom, false);
                if ($reflection !== null) {
                    $types[$reflection->getShortName()] = $reflection->getName();
                }
            }
        }

        $resolver = $container->findDefinition(MethodsResolver::class);
        $resolver->addMethodCall('registerTypeMap', [$types]);
        foreach ($configured as $class) {
            $resolver->addMethodCall('registerMethodsClass', [$class]);
        }

        $container->setAlias('atoms.methods_locator', (string) ServiceLocatorTagPass::register($container, $methods));
    }
}
