<?php

declare(strict_types=1);

namespace Atoms\Symfony\DependencyInjection;

use Atoms\AtomMethods;
use Atoms\Client\Callback\CallbackKernel;
use Atoms\Client\Callback\MethodsResolver;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Collects every container service extending {@see AtomMethods} — whether
 * autoconfigured via {@see self::TAG} or manually defined — together with any
 * classes listed under `atoms.methods_classes`, wires them into
 * {@see MethodsResolver} (for `#[MethodsFor]` overrides) and populates
 * {@see CallbackKernel}'s PSR-11 {@see \Symfony\Component\DependencyInjection\ServiceLocator}
 * so convention-named Methods classes get constructor injection automatically.
 *
 * Registered from {@see \Atoms\Symfony\AtomsBundle::build()} at default
 * `TYPE_BEFORE_OPTIMIZATION` priority so it runs after Symfony's
 * `ResolveClassPass` and `ResolveInstanceofConditionalsPass` (priority 100)
 * and before `ServiceLocatorTagPass` and `AutowirePass`.
 */
final class MethodsPass implements CompilerPassInterface
{
    public const TAG = 'atoms.methods';

    /**
     * Load-to-compile hand-off for `atoms.methods_classes`, matching
     * {@see HttpClientPass::CONFIGURED_SERVICE_ID_PARAMETER}.
     *
     * @internal
     */
    public const CONFIGURED_CLASSES_PARAMETER = 'atoms.methods_classes';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(CallbackKernel::class) || !$container->hasDefinition(MethodsResolver::class)) {
            return;
        }

        $resolver = $container->getDefinition(MethodsResolver::class);

        /** @var array<class-string, Reference> $locatorMap */
        $locatorMap = [];

        /** @var array<class-string, true> $discoveredClasses */
        $discoveredClasses = [];

        foreach (array_keys($container->findTaggedServiceIds(self::TAG)) as $id) {
            $this->collectService($container, $id, $locatorMap, $discoveredClasses);
        }

        foreach (array_keys($container->getDefinitions()) as $id) {
            $this->collectService($container, $id, $locatorMap, $discoveredClasses);
        }

        $configuredRaw = $container->hasParameter(self::CONFIGURED_CLASSES_PARAMETER)
            ? $container->getParameter(self::CONFIGURED_CLASSES_PARAMETER)
            : [];

        $resolverClasses = array_keys($discoveredClasses);

        if (is_array($configuredRaw)) {
            foreach ($configuredRaw as $methodsClass) {
                if (!is_string($methodsClass) || $methodsClass === '') {
                    continue;
                }

                /** @var class-string $methodsClass */
                if (!$container->has($methodsClass)) {
                    $container->register($methodsClass, $methodsClass)
                        ->setAutowired(true)
                        ->setPublic(false);
                }

                $locatorMap[$methodsClass] = new Reference($methodsClass);
                $resolverClasses[] = $methodsClass;
            }
        }

        // Deduplicate while keeping explicit methods_classes after discovered
        // services so explicit config wins if two #[MethodsFor] classes target
        // the same Atom.
        /** @var array<class-string, true> $orderedResolverClasses */
        $orderedResolverClasses = [];
        foreach ($resolverClasses as $class) {
            unset($orderedResolverClasses[$class]);
            $orderedResolverClasses[$class] = true;
        }

        foreach (array_keys($orderedResolverClasses) as $class) {
            $resolver->addMethodCall('registerMethodsClass', [$class]);
        }

        $container->getDefinition(CallbackKernel::class)
            ->setArgument(7, ServiceLocatorTagPass::register($container, $locatorMap));
    }

    /**
     * @param array<class-string, Reference> $locatorMap
     * @param array<class-string, true>      $discoveredClasses
     */
    private function collectService(
        ContainerBuilder $container,
        string $id,
        array &$locatorMap,
        array &$discoveredClasses,
    ): void {
        if (!$container->hasDefinition($id)) {
            return;
        }

        $definition = $container->getDefinition($id);

        if (
            $definition->isAbstract()
            || $definition->isSynthetic()
            || $definition->hasErrors()
            || $definition->getDecoratedService() !== null
            || $definition->hasTag('container.excluded')
        ) {
            return;
        }

        $class = $this->resolveClass($container, $definition);
        if ($class === null) {
            return;
        }

        $reflection = $container->getReflectionClass($class, false);
        if (
            $reflection === null
            || $reflection->isAbstract()
            || $reflection->isInterface()
            || !$reflection->isSubclassOf(AtomMethods::class)
        ) {
            return;
        }

        /** @var class-string $fqcn */
        $fqcn = $reflection->getName();

        if (isset($locatorMap[$fqcn])) {
            return;
        }

        $targetId = $container->has($fqcn) ? $fqcn : $id;
        $locatorMap[$fqcn] = new Reference($targetId);
        $discoveredClasses[$fqcn] = true;
    }

    private function resolveClass(ContainerBuilder $container, Definition $definition): ?string
    {
        $class = $definition->getClass();
        $cursor = $definition;

        while (($class === null || $class === '') && $cursor instanceof ChildDefinition) {
            $parentId = $cursor->getParent();
            if (!$container->hasDefinition($parentId)) {
                break;
            }

            $cursor = $container->getDefinition($parentId);
            $class = $cursor->getClass();
        }

        return is_string($class) && $class !== '' ? $class : null;
    }
}
