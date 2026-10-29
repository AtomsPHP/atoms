<?php

declare(strict_types=1);

namespace Atoms\Symfony\DependencyInjection;

use Atoms\Client\Callback\LazyNonceStore;
use Atoms\Client\Callback\NonceStore;
use Atoms\Symfony\Command\PruneNoncesCommand;
use Atoms\Symfony\Migrations\Version20261029000000;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;

final class NonceStorePass implements CompilerPassInterface
{
    public const CONFIG_PARAMETER = 'atoms.callback_nonce';
    public const WINDOW_PARAMETER = 'atoms.callback_timestamp_window';

    public function process(ContainerBuilder $container): void
    {
        if ($container->hasDefinition(NonceStore::class) || $container->hasAlias(NonceStore::class)) {
            return;
        }

        /** @var array<string, mixed> $config */
        $config = $container->getParameter(self::CONFIG_PARAMETER);
        // These values determine which services and migrations enter the compiled container.
        foreach (['driver', 'redis_service'] as $key) {
            $config[$key] = $container->resolveEnvPlaceholders(
                $container->getParameterBag()->resolveValue($config[$key] ?? null),
                true,
            );
        }
        $container->setParameter(self::CONFIG_PARAMETER, $config);
        $redisId = $config['redis_service'] ?? null;
        $container->register('atoms.nonce_store.factory', NonceStoreFactory::class)->setArguments([
            $config,
            $container->getParameter(self::WINDOW_PARAMETER),
            is_string($redisId) && $redisId !== ''
                ? new ServiceClosureArgument(new Reference($redisId, ContainerInterface::NULL_ON_INVALID_REFERENCE))
                : null,
            new ServiceClosureArgument(new Reference('doctrine.migrations.dependency_factory', ContainerInterface::NULL_ON_INVALID_REFERENCE)),
        ]);
        $container->register('atoms.nonce_store.backend', NonceStore::class)
            ->setFactory([new Reference('atoms.nonce_store.factory'), 'create']);
        $container->register('atoms.nonce_store', LazyNonceStore::class)
            ->setArguments([new ServiceClosureArgument(new Reference('atoms.nonce_store.backend'))]);
        $container->setAlias(NonceStore::class, 'atoms.nonce_store');

        if (($config['driver'] ?? null) !== 'database') {
            return;
        }
        if ($container->hasDefinition('doctrine.migrations.configuration')) {
            $container->getDefinition('doctrine.migrations.configuration')
                ->addMethodCall('addMigrationClass', [Version20261029000000::class]);
        }
        if ($container->hasParameter('doctrine.connections')) {
            /** @var array<string, string> $connections */
            $connections = $container->getParameter('doctrine.connections');
            $filter = $container->register('atoms.nonce_schema_filter', NonceSchemaFilter::class);
            foreach (array_keys($connections) as $name) {
                $filter->addTag('doctrine.dbal.schema_filter', ['connection' => $name]);
            }
        }
        if (class_exists(Command::class)) {
            $container->register('atoms.command.nonces_prune', PruneNoncesCommand::class)
                ->setArguments([new ServiceClosureArgument(new Reference('atoms.nonce_store.backend'))])
                ->setPublic(true)
                ->addTag('console.command');
        }
    }
}
