<?php

declare(strict_types=1);

namespace Atoms\Symfony\Tests\DependencyInjection;

use Atoms\AtomMethods;
use Atoms\Attributes\MethodsFor;
use Atoms\Client\Callback\MethodsResolver;
use Atoms\Symfony\DependencyInjection\MethodsLocatorPass;
use Atoms\Symfony\Tests\Fixtures\CustomOtherRoomMethods;
use Atoms\Symfony\Tests\Fixtures\OtherRoom;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

#[MethodsFor(OtherRoom::class)]
class DiscoveredMethods extends AtomMethods {}

final class MethodsLocatorPassTest extends TestCase
{
    public function testUnconfiguredContainerIsIgnored(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter(MethodsLocatorPass::CONFIG_PARAMETER, []);
        (new MethodsLocatorPass())->process($container);
        self::assertFalse($container->has('atoms.methods_locator'));

        $container = new ContainerBuilder();
        $container->register(MethodsResolver::class);
        (new MethodsLocatorPass())->process($container);
        self::assertFalse($container->has('atoms.methods_locator'));
    }

    public function testDiscoverySkipsInvalidDefinitionsAndRegistersExplicitOverridesLast(): void
    {
        $container = $this->container([CustomOtherRoomMethods::class]);
        $container->register('abstract', DiscoveredMethods::class)->setAbstract(true)->addTag(MethodsLocatorPass::TAG);
        $container->register('synthetic', DiscoveredMethods::class)->setSynthetic(true)->addTag(MethodsLocatorPass::TAG);
        $container->register('excluded', DiscoveredMethods::class)->addTag('container.excluded')->addTag(MethodsLocatorPass::TAG);
        $container->register('invalid', \stdClass::class)->addTag(MethodsLocatorPass::TAG);
        $container->register('error', DiscoveredMethods::class)->addError('invalid')->addTag(MethodsLocatorPass::TAG);
        $container->register('decorator', DiscoveredMethods::class)->setDecoratedService('target')->addTag(MethodsLocatorPass::TAG);
        $container->register('target', DiscoveredMethods::class)->addTag(MethodsLocatorPass::TAG);
        $container->register(CustomOtherRoomMethods::class, CustomOtherRoomMethods::class)
            ->addTag('container.excluded')
            ->addTag(MethodsLocatorPass::TAG);

        (new MethodsLocatorPass())->process($container);

        $calls = $container->getDefinition(MethodsResolver::class)->getMethodCalls();
        self::assertSame(['registerMethodsClass', [DiscoveredMethods::class]], $calls[1]);
        self::assertSame(['registerMethodsClass', [CustomOtherRoomMethods::class]], end($calls));
        self::assertFalse($container->getDefinition(CustomOtherRoomMethods::class)->hasTag('container.excluded'));
        $locator = $container->findDefinition('atoms.methods_locator');
        $values = $locator->getArgument(0);
        self::assertSame('target', (string) $values[DiscoveredMethods::class]->getValues()[0]);
        self::assertInstanceOf(Reference::class, $values[CustomOtherRoomMethods::class]->getValues()[0]);
    }

    public function testExplicitMethodsOverrideDiscoveredAttribute(): void
    {
        $container = $this->container([CustomOtherRoomMethods::class]);
        $container->getDefinition(MethodsResolver::class)->setPublic(true);
        $container->register('discovered', DiscoveredMethods::class)->addTag(MethodsLocatorPass::TAG);
        $container->addCompilerPass(new MethodsLocatorPass());
        $container->compile();

        self::assertSame(CustomOtherRoomMethods::class, $container->get(MethodsResolver::class)->resolve('OtherRoom'));
    }

    public function testChildDefinitionInheritsClassAndClassAliasIsPreserved(): void
    {
        $container = $this->container();
        $container->register('parent', DiscoveredMethods::class)->setAbstract(true);
        $container->setDefinition('child', (new ChildDefinition('parent'))->setAutoconfigured(true));
        $container->setAlias(DiscoveredMethods::class, 'child');

        (new MethodsLocatorPass())->process($container);

        $values = $container->findDefinition('atoms.methods_locator')->getArgument(0);
        self::assertSame(DiscoveredMethods::class, (string) $values[DiscoveredMethods::class]->getValues()[0]);
    }

    /** @param list<class-string> $configured */
    private function container(array $configured = []): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter(MethodsLocatorPass::CONFIG_PARAMETER, $configured);
        $container->register(MethodsResolver::class);

        return $container;
    }
}
