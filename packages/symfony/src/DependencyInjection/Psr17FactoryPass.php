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
 * config key, read back via {@see self::CONFIGURED_SERVICE_ID_PARAMETER})
 * else the bundled Guzzle default.
 */
final class Psr17FactoryPass implements CompilerPassInterface
{
    /**
     * Where AtomsBundle leaves the parsed `psr17_factory` config value for
     * this pass to read back.
     *
     * @internal Same load-phase-to-compile-phase hand-off as
     * {@see HttpClientPass::CONFIGURED_SERVICE_ID_PARAMETER}, with the same
     * caveat: not a supported knob. Bind the `atoms.psr17_factory` service id
     * directly to override the resolution outright.
     */
    public const CONFIGURED_SERVICE_ID_PARAMETER = 'atoms.psr17_factory_service_id';

    public function process(ContainerBuilder $container): void
    {
        // An app (or another bundle) that binds 'atoms.psr17_factory' itself
        // has said the last word; this pass only ever fills an empty slot.
        if ($container->hasAlias('atoms.psr17_factory') || $container->hasDefinition('atoms.psr17_factory')) {
            return;
        }

        $configuredServiceId = $container->hasParameter(self::CONFIGURED_SERVICE_ID_PARAMETER)
            ? $container->getParameter(self::CONFIGURED_SERVICE_ID_PARAMETER)
            : null;

        if (is_string($configuredServiceId) && $configuredServiceId !== '') {
            $container->setAlias('atoms.psr17_factory', $configuredServiceId)->setPublic(true);

            return;
        }

        $container->setAlias('atoms.psr17_factory', 'atoms.psr17_factory.guzzle_factory')->setPublic(true);
    }
}
