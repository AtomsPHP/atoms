<?php

declare(strict_types=1);

namespace Atoms\Symfony\DependencyInjection;

use Atoms\AtomJob;
use Atoms\Symfony\Messenger\AtomJobHandler;
use Symfony\Component\DependencyInjection\Argument\BoundArgument;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\TypedReference;

/**
 * Compiles per-job argument {@see \Symfony\Component\DependencyInjection\ServiceLocator}s
 * for {@see AtomJobHandler} so `AtomJob::handle()` can take autowired Symfony
 * services (including private services, interface aliases, `#[Target]`,
 * `#[Autowire]`, and service bindings) — the same compile-time pattern
 * Symfony's own `RegisterControllerArgumentLocatorsPass` uses for controller
 * action arguments.
 *
 * Importantly, this pass never adds a reference to an {@see AtomJob} class
 * itself: job instances are reconstructed from wire payloads, never built by
 * the container, so private `AtomJob` definitions discovered from
 * `services.yaml` are cleanly dropped by `RemoveUnusedDefinitionsPass` while
 * the services their `handle()` methods depend on stay alive inside the
 * compiled locator.
 *
 * Registered after {@see MessengerBridgePass} and before FrameworkBundle's
 * `MessengerPass`.
 */
final class AtomJobPass implements CompilerPassInterface
{
    public const TAG = 'atoms.job';

    /**
     * Load-to-compile hand-off for `atoms.job_classes`, matching
     * {@see HttpClientPass::CONFIGURED_SERVICE_ID_PARAMETER}.
     *
     * @internal
     */
    public const CONFIGURED_CLASSES_PARAMETER = 'atoms.job_classes';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(AtomJobHandler::class)) {
            return;
        }

        /** @var array<class-string<AtomJob>, array{\ReflectionClass<AtomJob>, array<string, BoundArgument>}> $jobs */
        $jobs = [];

        foreach (array_keys($container->findTaggedServiceIds(self::TAG)) as $id) {
            $this->collectService($container, $id, $jobs);
        }

        foreach (array_keys($container->getDefinitions()) as $id) {
            $this->collectService($container, $id, $jobs);
        }

        $configuredRaw = $container->hasParameter(self::CONFIGURED_CLASSES_PARAMETER)
            ? $container->getParameter(self::CONFIGURED_CLASSES_PARAMETER)
            : [];

        if (is_array($configuredRaw)) {
            foreach ($configuredRaw as $jobClass) {
                if (!is_string($jobClass) || $jobClass === '' || isset($jobs[$jobClass])) {
                    continue;
                }

                $reflection = $container->getReflectionClass($jobClass, false);
                if (
                    $reflection === null
                    || $reflection->isAbstract()
                    || $reflection->isInterface()
                    || !$reflection->isSubclassOf(AtomJob::class)
                ) {
                    continue;
                }

                /** @var \ReflectionClass<AtomJob> $reflection */
                $jobs[$reflection->getName()] = [$reflection, []];
            }
        }

        /** @var array<class-string<AtomJob>, Reference> $jobLocators */
        $jobLocators = [];

        foreach ($jobs as $jobClass => [$reflection, $bindings]) {
            $args = $this->buildHandleArguments($container, $jobClass, $reflection, $bindings);
            if ($args === []) {
                continue;
            }

            $jobLocators[$jobClass] = ServiceLocatorTagPass::register($container, $args, $jobClass . '::handle()');
        }

        $container->getDefinition(AtomJobHandler::class)
            ->setArgument('$container', ServiceLocatorTagPass::register($container, $jobLocators));
    }

    /**
     * @param array<class-string<AtomJob>, array{\ReflectionClass<AtomJob>, array<string, BoundArgument>}> $jobs
     */
    private function collectService(ContainerBuilder $container, string $id, array &$jobs): void
    {
        if (!$container->hasDefinition($id)) {
            return;
        }

        $definition = $container->getDefinition($id);

        if (
            $definition->isAbstract()
            || $definition->isSynthetic()
            || $definition->hasErrors()
            || $definition->hasTag('container.excluded')
        ) {
            return;
        }

        [$class, $bindings] = $this->resolveClassAndBindings($container, $definition);
        if ($class === null) {
            return;
        }

        $reflection = $container->getReflectionClass($class, false);
        if (
            $reflection === null
            || $reflection->isAbstract()
            || $reflection->isInterface()
            || !$reflection->isSubclassOf(AtomJob::class)
        ) {
            return;
        }

        /** @var \ReflectionClass<AtomJob> $reflection */
        $fqcn = $reflection->getName();

        if (isset($jobs[$fqcn])) {
            return;
        }

        $jobs[$fqcn] = [$reflection, $bindings];
    }

    /**
     * @param class-string<AtomJob>      $jobClass
     * @param \ReflectionClass<AtomJob>  $reflection
     * @param array<string, BoundArgument> $bindings
     *
     * @return array<string, Reference>
     */
    private function buildHandleArguments(
        ContainerBuilder $container,
        string $jobClass,
        \ReflectionClass $reflection,
        array $bindings,
    ): array {
        if (!$reflection->hasMethod('handle')) {
            return [];
        }

        $method = $reflection->getMethod('handle');
        if (!$method->isPublic() || $method->isAbstract() || $method->isStatic()) {
            return [];
        }

        $parameters = $method->getParameters();
        if ($parameters === []) {
            return [];
        }

        $parameterBag = $container->getParameterBag();
        $args = [];

        foreach ($parameters as $param) {
            $paramName = $param->getName();
            $type = $param->getType();
            $namedType = ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) ? $type->getName() : null;
            $invalidBehavior = ($param->allowsNull() || $param->isDefaultValueAvailable())
                ? ContainerInterface::IGNORE_ON_INVALID_REFERENCE
                : ContainerInterface::RUNTIME_EXCEPTION_ON_INVALID_REFERENCE;

            $autowireAttributes = $param->getAttributes(Autowire::class, \ReflectionAttribute::IS_INSTANCEOF);
            if ($autowireAttributes !== []) {
                $attribute = $autowireAttributes[0]->newInstance();
                $value = $parameterBag->resolveValue($attribute->value);

                if ($value instanceof Reference) {
                    $args[$paramName] = $namedType !== null
                        ? new TypedReference((string) $value, $namedType, $invalidBehavior, $paramName)
                        : new Reference((string) $value, $invalidBehavior);
                } else {
                    $args[$paramName] = $this->registerValueReference($container, $jobClass, $paramName, $value);
                }

                continue;
            }

            $targetAttribute = null;
            $parsedName = null;
            $targetName = Target::parseName($param, $targetAttribute, $parsedName);

            $boundValue = $this->matchBinding($bindings, $namedType, $targetName, $parsedName, $paramName);
            if ($boundValue !== null) {
                [$value] = $boundValue;
                $args[$paramName] = $value instanceof Reference
                    ? $value
                    : $this->registerValueReference($container, $jobClass, $paramName, $value);

                continue;
            }

            if (
                $namedType === null
                || in_array($namedType, ['self', 'static', 'parent'], true)
                || is_subclass_of($namedType, \UnitEnum::class)
            ) {
                continue;
            }

            $args[$paramName] = new TypedReference(
                $namedType,
                $namedType,
                $invalidBehavior,
                $targetName,
                $targetAttribute !== null ? [$targetAttribute] : [],
            );
        }

        return $args;
    }

    /**
     * @param array<string, BoundArgument> $bindings
     *
     * @return array{0: mixed}|null
     */
    private function matchBinding(
        array $bindings,
        ?string $namedType,
        string $targetName,
        ?string $parsedName,
        string $paramName,
    ): ?array {
        if ($bindings === []) {
            return null;
        }

        $candidates = [];
        if ($namedType !== null) {
            $candidates[] = $namedType . ' $' . $targetName;
            if ($parsedName !== null) {
                $candidates[] = $namedType . ' $' . $parsedName;
            }
            $candidates[] = $namedType . ' $' . $paramName;
        }

        $candidates[] = '$' . $targetName;
        if ($parsedName !== null) {
            $candidates[] = '$' . $parsedName;
        }
        $candidates[] = '$' . $paramName;

        if ($namedType !== null) {
            $candidates[] = $namedType;
        }

        foreach ($candidates as $key) {
            if (!isset($bindings[$key])) {
                continue;
            }

            $binding = $bindings[$key];
            [$bindingValue, $bindingId, , $bindingType, $bindingFile] = $binding->getValues();
            $binding->setValues([$bindingValue, $bindingId, true, $bindingType, $bindingFile]);

            return [$bindingValue];
        }

        return null;
    }

    private function registerValueReference(
        ContainerBuilder $container,
        string $jobClass,
        string $paramName,
        mixed $value,
    ): Reference {
        $serviceId = '.atoms.job_arg.' . ContainerBuilder::hash([$jobClass, $paramName, $value]);

        if (!$container->hasDefinition($serviceId)) {
            $container->register($serviceId, 'mixed')
                ->setFactory('current')
                ->addArgument([$value])
                ->setPublic(false);
        }

        return new Reference($serviceId);
    }

    /**
     * @return array{string|null, array<string, BoundArgument>}
     */
    private function resolveClassAndBindings(ContainerBuilder $container, Definition $definition): array
    {
        $class = $definition->getClass();
        /** @var array<string, BoundArgument> $bindings */
        $bindings = $definition->getBindings();
        $cursor = $definition;

        while ($cursor instanceof ChildDefinition) {
            $parentId = $cursor->getParent();
            if (!$container->hasDefinition($parentId)) {
                break;
            }

            $cursor = $container->getDefinition($parentId);
            if ($class === null || $class === '') {
                $class = $cursor->getClass();
            }

            /** @var array<string, BoundArgument> $parentBindings */
            $parentBindings = $cursor->getBindings();
            $bindings += $parentBindings;
        }

        return [is_string($class) && $class !== '' ? $class : null, $bindings];
    }
}
