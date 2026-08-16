<?php

declare(strict_types=1);

namespace Atoms\Symfony\Tests\DependencyInjection;

use Atoms\Client\AtomsClient;
use Atoms\Client\AtomsConfig;
use Atoms\Client\Callback\CallbackKernel;
use Atoms\Client\Callback\HmacVerifier;
use Atoms\Client\Callback\MethodsResolver;
use Atoms\Client\Callback\NullQueueBridge;
use Atoms\Client\Callback\QueueBridge;
use Atoms\Client\Crypto\KeyDerivation;
use Atoms\Client\Tickets\TicketIssuer;
use Atoms\Symfony\AtomsBundle;
use Atoms\Symfony\Command\AtomsDeployCommand;
use Atoms\Symfony\Controller\CallbackController;
use Atoms\Symfony\Messenger\MessengerQueueBridge;
use Atoms\Symfony\Routing\AtomsRouteLoader;
use Atoms\Symfony\Tests\Fixtures\CustomOtherRoomMethods;
use Atoms\Symfony\Tests\Fixtures\OtherRoom;
use Atoms\Symfony\Tests\Support\FakePsr17Factory;
use Atoms\Symfony\Tests\Support\FakePsr18Client;
use Atoms\Symfony\Tests\Support\RecordingMessageBus;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface as PsrContainerInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Messenger\MessageBusInterface;

final class AtomsBundleExtensionTest extends TestCase
{
    /** The reference vector's secret (docs/shared-secret.md). */
    private const SECRET = 'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=';

    /** The bearer HKDF derives from it. */
    private const BEARER = 'Dx6RY9LS43pOQhM4PMdaUWx3lk9mfyiiJZFfJtvl9E0=';

    /** A second valid secret: 32 bytes of 0x02. */
    private const PREVIOUS_SECRET = 'AgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgI=';

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
            'endpoint' => 'https://atoms.example.workers.dev',
            'shared_secret' => self::SECRET,
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
            'http_client' => 'test.psr18_client',
        ]);
        $container->register('test.psr18_client', FakePsr18Client::class)->setPublic(true);
        $container->compile();

        $config = $container->get(AtomsConfig::class);
        self::assertInstanceOf(AtomsConfig::class, $config);
        self::assertSame('https://atoms.example.workers.dev', $config->endpoint);
        self::assertSame(self::SECRET, $config->sharedSecret);
        self::assertSame(self::BEARER, $config->bearerToken());
        self::assertNull($config->sharedSecretPrevious);
        self::assertSame('staging', $config->environment);
        self::assertSame(5.5, $config->timeout);
        self::assertSame(7, $config->maxAttempts);
        self::assertSame(60000, $config->wsTicketTtlMs);
    }

    public function testTicketIssuerResolvesFromTheContainerAndIsUsable(): void
    {
        $container = $this->buildContainer();
        $container->compile();

        self::assertTrue($container->has('atoms.ticket_issuer'));

        $issuer = $container->get('atoms.ticket_issuer');
        self::assertInstanceOf(TicketIssuer::class, $issuer);

        $ticket = $issuer->issue('GameRoom', 'g-1');
        self::assertStringStartsWith('v1.', $ticket->ticket);
    }

    public function testConfiguredWsTicketTtlMsLandsInAtomsConfig(): void
    {
        $container = $this->buildContainer(['ws_ticket_ttl_ms' => 15000]);
        $container->compile();

        $config = $container->get(AtomsConfig::class);
        self::assertInstanceOf(AtomsConfig::class, $config);
        self::assertSame(15000, $config->wsTicketTtlMs);
    }

    public function testRotationOverlapLandsInAtomsConfig(): void
    {
        $container = $this->buildContainer(['shared_secret_previous' => self::PREVIOUS_SECRET]);
        $container->compile();

        $config = $container->get(AtomsConfig::class);
        self::assertInstanceOf(AtomsConfig::class, $config);
        self::assertSame(self::PREVIOUS_SECRET, $config->sharedSecretPrevious);
        self::assertCount(2, $config->callbackKeys());
        self::assertSame(self::BEARER, $config->bearerToken(), 'a sender emits the current secret\'s bearer');
    }

    public function testTheSharedSecretIsRequired(): void
    {
        $this->expectException(\Exception::class);

        $container = new ContainerBuilder();
        $bundle = new AtomsBundle();
        $extension = $bundle->getContainerExtension();
        self::assertNotNull($extension);

        $container->registerExtension($extension);
        $container->loadFromExtension($extension->getAlias(), ['endpoint' => 'https://atoms.example.workers.dev']);
        $container->compile();
    }

    public function testAnEmptySharedSecretIsRejectedWhileCompiling(): void
    {
        $container = $this->buildContainer(['shared_secret' => '']);

        $this->expectException(\Exception::class);

        $container->compile();
    }

    public function testAMalformedSharedSecretThrowsE105OnResolution(): void
    {
        $container = $this->buildContainer(['shared_secret' => 'not-base64-32-bytes']);
        $container->compile();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/ATOMS-E105/');

        $container->get(AtomsConfig::class);
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

    /**
     * Pins Psr17FactoryPass's two-step contract (configured service id, else
     * Guzzle) against the "helpful" auto-detection that Psr17FactoryPass
     * deliberately does *not* do, unlike HttpClientPass. Registering an app
     * service under every relevant PSR-17 interface — without setting
     * `psr17_factory` — must still resolve to the bundled Guzzle factory:
     * PSR-17 spans several factory interfaces, and there is no single
     * "the app's PSR-17 factory" service id to detect, only individual
     * interfaces that may be bound to different implementations. See
     * Psr17FactoryPass and the `psr17_factory` config node's ->info().
     */
    public function testPsr17FactoryDoesNotAutoDetectAnAppServiceEvenWhenAllFourInterfacesAreImplemented(): void
    {
        $container = $this->buildContainer();
        $container->register('test.app_psr17_factory', FakePsr17Factory::class)->setPublic(true);
        $container->setAlias(RequestFactoryInterface::class, 'test.app_psr17_factory')->setPublic(true);
        $container->setAlias(ResponseFactoryInterface::class, 'test.app_psr17_factory')->setPublic(true);
        $container->setAlias(ServerRequestFactoryInterface::class, 'test.app_psr17_factory')->setPublic(true);
        $container->setAlias(StreamFactoryInterface::class, 'test.app_psr17_factory')->setPublic(true);
        $container->compile();

        self::assertInstanceOf(HttpFactory::class, $container->get('atoms.psr17_factory'));
        self::assertNotInstanceOf(FakePsr17Factory::class, $container->get('atoms.psr17_factory'));
    }

    public function testPsr17FactoryResolvesToConfiguredServiceId(): void
    {
        $container = $this->buildContainer(['psr17_factory' => 'test.app_psr17_factory']);
        $container->register('test.app_psr17_factory', FakePsr17Factory::class)->setPublic(true);
        $container->compile();

        // Same "identity survives, not the private id" pattern as
        // testHttpClientFallsBackToAppDefinedClientInterfaceService: the alias
        // gets replaced by the target definition during compilation.
        self::assertInstanceOf(FakePsr17Factory::class, $container->get('atoms.psr17_factory'));
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

    public function testQueueBridgeDefaultCarriesASymfonySpecificHint(): void
    {
        $container = $this->buildContainer();
        $container->compile();

        $bridge = $container->get(QueueBridge::class);
        self::assertInstanceOf(NullQueueBridge::class, $bridge);

        $hint = (new \ReflectionProperty(NullQueueBridge::class, 'hint'))->getValue($bridge);
        self::assertSame(
            'Install symfony/messenger and register a message bus so '
            . 'Atoms\Symfony\Messenger\MessengerQueueBridge is wired automatically, '
            . 'or bind your own QueueBridge service.',
            $hint,
        );
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
        $container = $this->buildContainer();
        $container->compile();

        self::assertInstanceOf(CallbackKernel::class, $container->get(CallbackKernel::class));
        self::assertInstanceOf(CallbackController::class, $container->get(CallbackController::class));
    }

    public function testCallbackVerifierUsesKeysDerivedFromBothSecrets(): void
    {
        $container = $this->buildContainer(['shared_secret_previous' => self::PREVIOUS_SECRET]);
        $container->compile();

        $kernel = $container->get(CallbackKernel::class);
        self::assertInstanceOf(CallbackKernel::class, $kernel);

        $verifier = (new \ReflectionProperty(CallbackKernel::class, 'verifier'))->getValue($kernel);
        self::assertInstanceOf(HmacVerifier::class, $verifier);

        $message = "v1\n1755200000\nabc\n{}";

        foreach ([self::SECRET, self::PREVIOUS_SECRET] as $secret) {
            $tag = hash_hmac('sha256', $message, KeyDerivation::callbackKey($secret), true);
            self::assertTrue($verifier->verify($message, $tag));
        }
    }

    public function testCallbackTimestampWindowDefaultsTo300Seconds(): void
    {
        $container = $this->buildContainer();
        $container->compile();

        $kernel = $container->get(CallbackKernel::class);
        self::assertInstanceOf(CallbackKernel::class, $kernel);
        self::assertSame(300, (new \ReflectionProperty(CallbackKernel::class, 'timestampWindow'))->getValue($kernel));
    }

    public function testCallbackTimestampWindowIsConfigurable(): void
    {
        $container = $this->buildContainer(['callback_timestamp_window' => 60]);
        $container->compile();

        $kernel = $container->get(CallbackKernel::class);
        self::assertInstanceOf(CallbackKernel::class, $kernel);
        self::assertSame(60, (new \ReflectionProperty(CallbackKernel::class, 'timestampWindow'))->getValue($kernel));
    }

    public function testCallbackKernelLoggerIsNullWithoutAnAppLoggerService(): void
    {
        $container = $this->buildContainer();
        $container->compile();

        $kernel = $container->get(CallbackKernel::class);
        self::assertInstanceOf(CallbackKernel::class, $kernel);
        self::assertNull((new \ReflectionProperty(CallbackKernel::class, 'logger'))->getValue($kernel));
    }

    public function testCallbackKernelLoggerResolvesToAnAppLoggerServiceWhenOneExists(): void
    {
        $container = $this->buildContainer();
        $container->register('logger', NullLogger::class)->setPublic(false);
        $container->compile();

        $kernel = $container->get(CallbackKernel::class);
        self::assertInstanceOf(CallbackKernel::class, $kernel);
        self::assertInstanceOf(NullLogger::class, (new \ReflectionProperty(CallbackKernel::class, 'logger'))->getValue($kernel));
    }

    public function testMethodsClassesServiceLocatorResolvesAnAutowiredEntry(): void
    {
        $container = $this->buildContainer(['methods_classes' => [CustomOtherRoomMethods::class]]);
        $container->compile();

        $kernel = $container->get(CallbackKernel::class);
        self::assertInstanceOf(CallbackKernel::class, $kernel);

        $locator = (new \ReflectionProperty(CallbackKernel::class, 'container'))->getValue($kernel);
        self::assertInstanceOf(PsrContainerInterface::class, $locator);
        self::assertTrue($locator->has(CustomOtherRoomMethods::class));
        self::assertInstanceOf(CustomOtherRoomMethods::class, $locator->get(CustomOtherRoomMethods::class));
    }

    public function testRouteLoaderIsRegisteredWithTheRoutingLoaderTagAndConfiguredCallbackPath(): void
    {
        $container = $this->buildContainer(['callback_path' => '/custom/atoms/callback']);

        // loadFromExtension() only queues config; the extension actually loads
        // (registering 'atoms.routing.loader') during compile(), via the merge
        // pass — see AtomsBundle::build()'s docblock. 'atoms.routing.loader' is
        // private and otherwise unreferenced in this bare ContainerBuilder (no
        // FrameworkBundle routing.resolver to consume the routing.loader tag),
        // so keep it reachable through compilation via a throwaway public
        // alias — same "identity survives, not the private id" pattern as
        // testHttpClientFallsBackToAppDefinedClientInterfaceService.
        $container->setAlias('test.atoms_route_loader', 'atoms.routing.loader')->setPublic(true);
        $container->compile();

        self::assertTrue($container->hasDefinition('test.atoms_route_loader'));
        $definition = $container->getDefinition('test.atoms_route_loader');
        self::assertSame(AtomsRouteLoader::class, $definition->getClass());
        self::assertTrue($definition->hasTag('routing.loader'));

        $loader = $container->get('test.atoms_route_loader');
        self::assertInstanceOf(AtomsRouteLoader::class, $loader);

        $routes = $loader->load('.', 'atoms');
        $route = $routes->get('atoms_callback');
        self::assertNotNull($route);
        self::assertSame('/custom/atoms/callback', $route->getPath());
        self::assertSame(['POST'], $route->getMethods());
        self::assertSame(CallbackController::class, $route->getDefault('_controller'));
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
