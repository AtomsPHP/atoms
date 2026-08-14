<?php

declare(strict_types=1);

namespace Atoms\Symfony\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Resolves the `atoms.psr17_factory` alias at the compiler-pass phase, for
 * the same reason and with the same "read the config back via a parameter"
 * mechanism as {@see HttpClientPass}: registered from AtomsBundle::build(),
 * after every bundle's extension has loaded, so registration order never
 * matters. Precedence: explicitly configured service id (`psr17_factory`
 * config key, read back via the 'atoms.psr17_factory_service_id' parameter)
 * else the bundled Guzzle default.
 */
final class Psr17FactoryPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if ($container->hasAlias('atoms.psr17_factory') || $container->hasDefinition('atoms.psr17_factory')) {
            return;
        }

        $configuredServiceId = $container->hasParameter('atoms.psr17_factory_service_id')
            ? $container->getParameter('atoms.psr17_factory_service_id')
            : null;

        if (is_string($configuredServiceId) && $configuredServiceId !== '') {
            $container->setAlias('atoms.psr17_factory', $configuredServiceId)->setPublic(true);

            return;
        }

        $container->setAlias('atoms.psr17_factory', 'atoms.psr17_factory.guzzle_factory')->setPublic(true);
    }
}
