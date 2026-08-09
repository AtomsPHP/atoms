<?php

declare(strict_types=1);

namespace Atoms\Symfony;

use Atoms\Client\AtomsClient;
use Atoms\Client\AtomsConfig;
use Atoms\Client\Callback\CallbackKernel;
use Atoms\Client\Callback\Ed25519Verifier;
use Atoms\Client\Callback\InMemoryNonceStore;
use Atoms\Client\Callback\MethodsResolver;
use Atoms\Client\Callback\NonceStore;
use Atoms\Client\Callback\QueueBridge;
use Atoms\Symfony\Command\AtomsDeployCommand;
use Atoms\Symfony\Command\AtomsListCommand;
use Atoms\Symfony\Command\AtomsRollbackCommand;
use Atoms\Symfony\Controller\CallbackController;
use Atoms\Symfony\DependencyInjection\GuzzleFactory;
use Atoms\Symfony\DependencyInjection\HttpClientPass;
use Atoms\Symfony\DependencyInjection\MessengerBridgePass;
use Atoms\Symfony\Messenger\NullQueueBridge;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientInterface;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Console\Command\Command as ConsoleCommand;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * @internal Phase 1 layering test — not yet a supported product
 *
 * Skeleton Symfony bundle for Atoms (integration-plan §5.3): a deliberately
 * small, internal proof that `atoms/client` is sufficient monolith-side glue
 * and that `atoms/symfony` never needs `atoms/laravel` or Illuminate. Wires
 * AtomsClient, the callback stack (Ed25519 verification, Methods dispatch,
 * AtomJob reconstruction), an optional Messenger queue bridge, and thin
 * console wrappers around the `atoms` binary.
 *
 * If this bundle ever needs something from atoms/laravel to function, the
 * layering is wrong — fix the layering, not this file (docs/conventions.md).
 *
 * @phpstan-type AtomsBundleConfig array{
 *     environment: string,
 *     endpoint: string,
 *     api_key: string|null,
 *     timeout: float,
 *     max_attempts: int,
 *     platform_public_key: string|null,
 *     callback_path: string,
 *     http_client: string|null,
 *     methods_classes: list<class-string>,
 * }
 */
final class AtomsBundle extends AbstractBundle
{
    protected string $extensionAlias = 'atoms';

    /**
     * Compiler passes must be registered here, not from loadExtension(): the
     * merge phase runs each extension's load() against a sandboxed container
     * that rejects addCompilerPass() outright (a bundle's config isn't fully
     * merged yet at that point). HttpClientPass reads back the configured
     * service id via the 'atoms.http_client_service_id' parameter instead of
     * a constructor argument, since build() runs before config is parsed.
     */
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new HttpClientPass());
        $container->addCompilerPass(new MessengerBridgePass());
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('environment')->defaultValue('production')->end()
                ->scalarNode('endpoint')
                    ->isRequired()
                    ->cannotBeEmpty()
                    ->info('Base URL of your deployed Atoms Worker, e.g. https://atoms.<your-subdomain>.workers.dev (or http://127.0.0.1:8787 under `wrangler dev`).')
                ->end()
                ->scalarNode('api_key')
                    ->defaultNull()
                    ->info('Bearer key matching the Worker\'s ATOMS_APP_KEY. Leave null when the Worker runs with ATOMS_APP_KEY unset (its auth check is off entirely); an empty string is rejected as a misconfiguration.')
                ->end()
                ->floatNode('timeout')->defaultValue(10.0)->end()
                ->integerNode('max_attempts')->defaultValue(3)->end()
                ->scalarNode('platform_public_key')
                    ->defaultNull()
                    ->info('Ed25519 public key (base64) used to verify inbound callbacks.')
                ->end()
                ->scalarNode('callback_path')->defaultValue('/atoms/callback')->end()
                ->scalarNode('http_client')
                    ->defaultNull()
                    ->info('Service id of a PSR-18 client to use; falls back to an app-defined Psr\Http\Client\ClientInterface, then to Guzzle.')
                ->end()
                ->arrayNode('methods_classes')
                    ->info('FQCNs of AtomMethods classes to register on the MethodsResolver (needed for #[MethodsFor] overrides; convention-resolved classes need no entry here).')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                ->end()
            ->end();
    }

    /**
     * @param AtomsBundleConfig $config
     */
    public function loadExtension(array $config, ContainerConfigurator $configurator, ContainerBuilder $container): void
    {
        $container->setParameter('atoms.callback_path', $config['callback_path']);

        $this->registerConfig($container, $config);
        $this->registerHttpClient($container, $config);
        $this->registerClient($container);
        $this->registerCallbackStack($container, $config);
        $this->registerMessenger($container);
        $this->registerConsole($container);
    }

    /**
     * @param AtomsBundleConfig $config
     */
    private function registerConfig(ContainerBuilder $container, array $config): void
    {
        $container->register(AtomsConfig::class, AtomsConfig::class)
            ->setFactory([AtomsConfig::class, 'fromArray'])
            ->setArguments([[
                'endpoint' => $config['endpoint'],
                // Uncoerced: null means the Worker runs with auth off, while an
                // empty string is a misconfiguration AtomsConfig throws on.
                'apiKey' => $config['api_key'],
                'timeout' => $config['timeout'],
                'maxAttempts' => $config['max_attempts'],
                'platformPublicKey' => $config['platform_public_key'],
                'environment' => $config['environment'],
            ]])
            ->setPublic(true);
    }

    /**
     * @param AtomsBundleConfig $config
     */
    private function registerHttpClient(ContainerBuilder $container, array $config): void
    {
        $container->register('atoms.psr17_factory', HttpFactory::class)
            ->setFactory([GuzzleFactory::class, 'psr17Factory'])
            ->setPublic(false);

        $container->register('atoms.http_client.guzzle_factory', ClientInterface::class)
            ->setFactory([GuzzleFactory::class, 'httpClient'])
            ->setPublic(false);

        // Read back by HttpClientPass (registered in build()) once every bundle's
        // extension has loaded — see HttpClientPass for why that ordering matters.
        $container->setParameter('atoms.http_client_service_id', $config['http_client']);
    }

    private function registerClient(ContainerBuilder $container): void
    {
        $container->register(AtomsClient::class, AtomsClient::class)
            ->setArguments([
                new Reference(AtomsConfig::class),
                new Reference('atoms.http_client'),
                new Reference('atoms.psr17_factory'),
                new Reference('atoms.psr17_factory'),
            ])
            ->setPublic(false);

        // The class-named service stays private (framework code should depend on
        // the alias/autowiring); this alias is the stable, public handle tests use.
        $container->setAlias('atoms.client', AtomsClient::class)->setPublic(true);
    }

    /**
     * @param AtomsBundleConfig $config
     */
    private function registerCallbackStack(ContainerBuilder $container, array $config): void
    {
        $resolver = $container->register(MethodsResolver::class, MethodsResolver::class)->setPublic(true);
        foreach ($config['methods_classes'] as $methodsClass) {
            $resolver->addMethodCall('registerMethodsClass', [$methodsClass]);
        }

        $container->register(InMemoryNonceStore::class, InMemoryNonceStore::class)->setPublic(false);
        $container->setAlias(NonceStore::class, InMemoryNonceStore::class)->setPublic(false);

        $container->register(Ed25519Verifier::class, Ed25519Verifier::class)
            ->setArguments([(string) $config['platform_public_key']])
            ->setPublic(false);

        $container->register(CallbackKernel::class, CallbackKernel::class)
            ->setArguments([
                new Reference(Ed25519Verifier::class),
                new Reference(NonceStore::class),
                new Reference(MethodsResolver::class),
                new Reference(QueueBridge::class),
                new Reference('atoms.psr17_factory'),
                new Reference('atoms.psr17_factory'),
            ])
            ->setPublic(true);

        $container->register(CallbackController::class, CallbackController::class)
            ->setArguments([
                new Reference(CallbackKernel::class),
                new Reference('atoms.psr17_factory'),
            ])
            ->addTag('controller.service_arguments')
            ->setPublic(true);
    }

    private function registerMessenger(ContainerBuilder $container): void
    {
        $container->register(NullQueueBridge::class, NullQueueBridge::class)->setPublic(false);

        // Upgraded to MessengerQueueBridge by MessengerBridgePass (registered in
        // build()) when symfony/messenger is installed and the app has a message
        // bus service. Public so both this default and the upgrade survive
        // compilation as an independently fetchable service id.
        $container->setAlias(QueueBridge::class, NullQueueBridge::class)->setPublic(true);
    }

    private function registerConsole(ContainerBuilder $container): void
    {
        if (!class_exists(ConsoleCommand::class)) {
            return;
        }

        $commands = [
            'atoms.command.deploy' => AtomsDeployCommand::class,
            'atoms.command.rollback' => AtomsRollbackCommand::class,
            'atoms.command.list' => AtomsListCommand::class,
        ];

        foreach ($commands as $id => $class) {
            // Public: FrameworkBundle's AddConsoleCommandPass normally keeps
            // 'console.command'-tagged services alive regardless of visibility,
            // but that pass isn't present for a bundle compiled outside a full
            // Symfony app (e.g. these tests) — public sidesteps the dependency.
            $container->register($id, $class)
                ->setPublic(true)
                ->addTag('console.command');
        }
    }
}
