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
 * first. Precedence: an `atoms.http_client` binding the app made itself >
 * explicitly configured service id > an app-provided
 * Psr\Http\Client\ClientInterface service > the bundled Guzzle default.
 *
 * Registered from AtomsBundle::build() (not from loadExtension(), where
 * addCompilerPass() is rejected — see the comment there); reads the
 * configured service id back via {@see self::CONFIGURED_SERVICE_ID_PARAMETER},
 * since build() runs before the bundle's config is parsed.
 *
 * Resolving the alias here rather than in loadExtension() is the point, not
 * an accident of that ordering: a pass runs once every extension has loaded,
 * so nothing this decides can be invalidated by a bundle that registers
 * later. Doing it at load time would hand the alias to whichever extension
 * happened to run last — see AtomsBundle::registerHttpClient().
 */
final class HttpClientPass implements CompilerPassInterface
{
    /**
     * Where AtomsBundle leaves the parsed `http_client` config value for this
     * pass to read back.
     *
     * @internal The load-phase-to-compile-phase hand-off, not a supported
     * knob — and specifically not one because of how it behaves rather than
     * in spite of it. MergeExtensionConfigurationPass snapshots the main
     * container's parameters before any extension loads and re-adds them
     * after each merge, so a parameter of this name set by the app wins over
     * the bundle's own write and silently overrides an explicit `http_client`
     * config key. That precedence is an artefact of Symfony's merge order,
     * not a designed override. To override the resolution, bind the
     * `atoms.http_client` service id directly — the early return in
     * process() honours it, and it says what it means.
     */
    public const CONFIGURED_SERVICE_ID_PARAMETER = 'atoms.http_client_service_id';

    public function process(ContainerBuilder $container): void
    {
        // An app (or another bundle) that binds 'atoms.http_client' itself has
        // said the last word; this pass only ever fills an empty slot.
        if ($container->hasAlias('atoms.http_client') || $container->hasDefinition('atoms.http_client')) {
            return;
        }

        $configuredServiceId = $container->hasParameter(self::CONFIGURED_SERVICE_ID_PARAMETER)
            ? $container->getParameter(self::CONFIGURED_SERVICE_ID_PARAMETER)
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
