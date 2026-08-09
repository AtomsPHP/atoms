<?php

declare(strict_types=1);

namespace Atoms\Symfony\Tests\DependencyInjection;

use Atoms\Client\AtomsClient;
use Atoms\Client\AtomsConfig;
use Atoms\Client\Callback\CallbackKernel;
use Atoms\Client\Callback\MethodsResolver;
use Atoms\Client\Callback\QueueBridge;
use Atoms\Symfony\AtomsBundle;
use Atoms\Symfony\Command\AtomsDeployCommand;
use Atoms\Symfony\Controller\CallbackController;
use Atoms\Symfony\Messenger\MessengerQueueBridge;
use Atoms\Symfony\Messenger\NullQueueBridge;
use Atoms\Symfony\Tests\Fixtures\OtherRoom;
use Atoms\Symfony\Tests\Support\FakePsr18Client;
use Atoms\Symfony\Tests\Support\RecordingMessageBus;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Messenger\MessageBusInterface;

final class AtomsBundleExtensionTest extends TestCase
{
    /**
     * @param array<string, mixed> $config
     */
    private function buildContainer(array $config = []): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());
        $bundle = new AtomsBundle();
        $bundle->build($container);
        $extension = $bundle->getContainerExtension();
        self::assertNotNull($extension);

        $container->registerExtension($extension);
        $container->loadFromExtension($extension->getAlias(), array_merge([
            'project' => 'acme-games',
            'endpoint' => 'https://edge.atoms.test',
            'api_key' => 'atoms_v1_test_key',
        ], $config));

        return $container;
    }

    public function testExtensionLoadsAndAtomsClientResolvesAgainstConfiguredPsr18Client(): void
    {
        $container = $this->buildContainer(['http_client' => 'test.psr18_client']);
        $container->register('test.psr18_client', FakePsr18Client::class)->setPublic(true);
        $container->compile();

        self::assertTrue($container->has('atoms.client'));

        $client = $container->get('atoms.client');
        self::assertInstanceOf(AtomsClient::class, $client);
    }

    public function testConfigValuesLandInAtomsConfig(): void
    {
        $container = $this->buildContainer([
            'environment' => 'staging',
            'timeout' => 5.5,
            'max_attempts' => 7,
            'platform_public_key' => 'a-test-key',
            'http_client' => 'test.psr18_client',
        ]);
        $container->register('test.psr18_client', FakePsr18Client::class)->setPublic(true);
        $container->compile();

        $config = $container->get(AtomsConfig::class);
        self::assertInstanceOf(AtomsConfig::class, $config);
        self::assertSame('https://edge.atoms.test', $config->endpoint);
        self::assertSame('acme-games', $config->customer);
        self::assertSame('atoms_v1_test_key', $config->apiKey);
        self::assertSame('staging', $config->environment);
        self::assertSame(5.5, $config->timeout);
        self::assertSame(7, $config->maxAttempts);
        self::assertSame('a-test-key', $config->platformPublicKey);
    }

    public function testHttpClientFallsBackToAppDefinedClientInterfaceService(): void
    {
        $container = $this->buildContainer();
        $container->register(ClientInterface::class, FakePsr18Client::class)->setPublic(true);
        $container->compile();

        // The private target definition gets merged into the (public) alias id by
        // ReplaceAliasByActualDefinitionPass, so identity — not the alias's own
        // existence — is what proves resolution picked the app's service.
        self::assertInstanceOf(FakePsr18Client::class, $container->get('atoms.http_client'));
    }

    public function testHttpClientFallsBackToGuzzleWhenNothingConfigured(): void
    {
        $container = $this->buildContainer();
        $container->compile();

        self::assertInstanceOf(ClientInterface::class, $container->get('atoms.http_client'));
    }

    public function testMethodsClassesConfigRegistersMethodsForOverrides(): void
    {
        $container = $this->buildContainer([
            'methods_classes' => [\Atoms\Symfony\Tests\Fixtures\CustomOtherRoomMethods::class],
        ]);
        $container->compile();

        $resolver = $container->get(MethodsResolver::class);
        self::assertInstanceOf(MethodsResolver::class, $resolver);
        self::assertSame(\Atoms\Symfony\Tests\Fixtures\CustomOtherRoomMethods::class, $resolver->resolve(OtherRoom::class));
    }

    public function testQueueBridgeDefaultsToNullQueueBridgeWithoutAMessageBusService(): void
    {
        $container = $this->buildContainer();
        $container->compile();

        $bridge = $container->get(QueueBridge::class);
        self::assertInstanceOf(NullQueueBridge::class, $bridge);
    }

    public function testQueueBridgeUpgradesToMessengerWhenABusServiceExists(): void
    {
        $container = $this->buildContainer();
        $container->register(MessageBusInterface::class, RecordingMessageBus::class)->setPublic(false);
        $container->compile();

        $bridge = $container->get(QueueBridge::class);
        self::assertInstanceOf(MessengerQueueBridge::class, $bridge);
    }

    public function testCallbackKernelAndControllerAreWired(): void
    {
        $container = $this->buildContainer(['platform_public_key' => base64_encode(str_repeat('a', 32))]);
        $container->compile();

        self::assertInstanceOf(CallbackKernel::class, $container->get(CallbackKernel::class));
        self::assertInstanceOf(CallbackController::class, $container->get(CallbackController::class));
    }

    public function testConsoleCommandsAreRegisteredSinceSymfonyConsoleIsInstalled(): void
    {
        $container = $this->buildContainer();
        $container->compile();

        self::assertTrue($container->has('atoms.command.deploy'));
        self::assertInstanceOf(AtomsDeployCommand::class, $container->get('atoms.command.deploy'));
    }

    public function testMissingRequiredConfigFailsCompilation(): void
    {
        $this->expectException(\Exception::class);

        $container = new ContainerBuilder();
        $bundle = new AtomsBundle();
        $extension = $bundle->getContainerExtension();
        self::assertNotNull($extension);

        $container->registerExtension($extension);
        $container->loadFromExtension($extension->getAlias(), []);
        $container->compile();
    }
}
