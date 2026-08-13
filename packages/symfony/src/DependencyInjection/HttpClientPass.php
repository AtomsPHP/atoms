<?php

declare(strict_types=1);

namespace Atoms\Symfony\DependencyInjection;

use Psr\Http\Client\ClientInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Resolves the `atoms.http_client` alias at the compiler-pass phase — after
 * every bundle's extension has already loaded — so it never matters whether
 * this bundle or the one defining Psr\Http\Client\ClientInterface registers
 * first. Precedence: explicitly configured service id > an app-provided
 * Psr\Http\Client\ClientInterface service > the bundled Guzzle default.
 *
 * Registered from AtomsBundle::build() (not from loadExtension(), where
 * addCompilerPass() is rejected — see the comment there); reads the
 * configured service id back via the 'atoms.http_client_service_id'
 * parameter, since build() runs before the bundle's config is parsed.
 */
final class HttpClientPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if ($container->hasAlias('atoms.http_client') || $container->hasDefinition('atoms.http_client')) {
            return;
        }

        $configuredServiceId = $container->hasParameter('atoms.http_client_service_id')
            ? $container->getParameter('atoms.http_client_service_id')
            : null;

        if (is_string($configuredServiceId) && $configuredServiceId !== '') {
            $container->setAlias('atoms.http_client', $configuredServiceId)->setPublic(true);

            return;
        }

        if ($container->has(ClientInterface::class)) {
            $container->setAlias('atoms.http_client', ClientInterface::class)->setPublic(true);

            return;
        }

        $container->setAlias('atoms.http_client', 'atoms.http_client.guzzle_factory')->setPublic(true);
    }
}
