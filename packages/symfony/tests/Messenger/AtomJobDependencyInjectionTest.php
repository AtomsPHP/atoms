<?php

declare(strict_types=1);

namespace Atoms\Symfony\Tests\Messenger;

use Atoms\Client\Callback\QueueBridge;
use Atoms\Symfony\AtomsBundle;
use Atoms\Symfony\Messenger\AtomJobHandler;
use Atoms\Symfony\Messenger\AtomJobMessage;
use Atoms\Symfony\Tests\Fixtures\AbstractBaseJob;
use Atoms\Symfony\Tests\Fixtures\AutowireAttributeJob;
use Atoms\Symfony\Tests\Fixtures\InjectedScoreJob;
use Atoms\Symfony\Tests\Fixtures\ScoreFormatter;
use Atoms\Symfony\Tests\Fixtures\UnregisteredDependencyJob;
use Atoms\Symfony\Tests\Support\RecordingMessageBus;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Argument\BoundArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Messenger\MessageBusInterface;

final class AtomJobDependencyInjectionTest extends TestCase
{
    private const SECRET = 'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=';

    private mixed $baselineExceptionHandler;
    private mixed $baselineErrorHandler;
    private string $tempDir;
    /** @var list<Kernel> */
    private array $kernels = [];

    protected function setUp(): void
    {
        InjectedScoreJob::$handled = [];
        AutowireAttributeJob::$handled = [];

        $this->baselineExceptionHandler = get_exception_handler();
        $this->baselineErrorHandler = get_error_handler();
        $this->tempDir = sys_get_temp_dir() . '/atoms-job-di-' . bin2hex(random_bytes(8));
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        foreach ($this->kernels as $kernel) {
            $kernel->shutdown();
        }

        (new Filesystem())->remove($this->tempDir);

        for ($attempt = 0; get_exception_handler() !== $this->baselineExceptionHandler && $attempt < 64; $attempt++) {
            restore_exception_handler();
        }
        for ($attempt = 0; get_error_handler() !== $this->baselineErrorHandler && $attempt < 64; $attempt++) {
            restore_error_handler();
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function buildContainer(array $config = []): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', $this->tempDir);

        $bundle = new AtomsBundle();
        $bundle->build($container);
        $extension = $bundle->getContainerExtension();
        self::assertNotNull($extension);

        $container->registerExtension($extension);
        $container->loadFromExtension($extension->getAlias(), array_merge([
            'endpoint' => 'https://atoms.example.workers.dev',
            'shared_secret' => self::SECRET,
        ], $config));

        $container->register(MessageBusInterface::class, RecordingMessageBus::class)->setPublic(false);
        $container->setAlias('test.atom_job_handler', AtomJobHandler::class)->setPublic(true);

        return $container;
    }

    public function testAutoconfiguredJobInjectsPrivateServiceAndFallsBackForMissingOptionalDependencies(): void
    {
        $container = $this->buildContainer();
        $container->register(ScoreFormatter::class, ScoreFormatter::class)->setPublic(false);
        // Scalar constructor params ($playerId, $score) + autowire + autoconfigure,
        // matching standard Symfony services.yaml resource loading.
        $container->register(InjectedScoreJob::class, InjectedScoreJob::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setPublic(false);
        $container->compile();

        $handler = $container->get('test.atom_job_handler');
        self::assertInstanceOf(AtomJobHandler::class, $handler);

        $handler(new AtomJobMessage(InjectedScoreJob::class, ['playerId' => 'ada', 'score' => 21]));

        self::assertSame(
            [['playerId' => 'ada', 'formatted' => 'Score: 21', 'logged' => false]],
            InjectedScoreJob::$handled,
        );
    }

    public function testOptionalNullableHandleDependencyResolvesWhenRegisteredInContainer(): void
    {
        $container = $this->buildContainer();
        $container->register(ScoreFormatter::class, ScoreFormatter::class)->setPublic(false);
        $container->register('logger', NullLogger::class)->setPublic(false);
        $container->setAlias(LoggerInterface::class, 'logger')->setPublic(false);
        $container->register(InjectedScoreJob::class, InjectedScoreJob::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setPublic(false);
        $container->compile();

        $handler = $container->get('test.atom_job_handler');
        self::assertInstanceOf(AtomJobHandler::class, $handler);

        $handler(new AtomJobMessage(InjectedScoreJob::class, ['playerId' => 'grace', 'score' => 7]));

        self::assertSame(
            [['playerId' => 'grace', 'formatted' => 'Score: 7', 'logged' => true]],
            InjectedScoreJob::$handled,
        );
    }

    public function testJobRegisteredWithoutAutoconfigurationStillGetsHandleInjection(): void
    {
        $container = $this->buildContainer();
        $container->register(ScoreFormatter::class, ScoreFormatter::class)->setPublic(false);
        $container->register(InjectedScoreJob::class, InjectedScoreJob::class)
            ->setAutowired(false)
            ->setAutoconfigured(false)
            ->setPublic(false);
        $container->compile();

        $handler = $container->get('test.atom_job_handler');
        self::assertInstanceOf(AtomJobHandler::class, $handler);

        $handler(new AtomJobMessage(InjectedScoreJob::class, ['playerId' => 'lin', 'score' => 5]));

        self::assertSame(
            [['playerId' => 'lin', 'formatted' => 'Score: 5', 'logged' => false]],
            InjectedScoreJob::$handled,
        );
    }

    public function testConfiguredJobClassesInjectHandleDependenciesWithoutServiceRegistration(): void
    {
        $container = $this->buildContainer([
            'job_classes' => [InjectedScoreJob::class],
        ]);
        $container->register(ScoreFormatter::class, ScoreFormatter::class)
            ->setArguments(['Explicit'])
            ->setPublic(false);
        $container->compile();

        $handler = $container->get('test.atom_job_handler');
        self::assertInstanceOf(AtomJobHandler::class, $handler);

        $handler(new AtomJobMessage(InjectedScoreJob::class, ['playerId' => 'kai', 'score' => 9]));

        self::assertSame(
            [['playerId' => 'kai', 'formatted' => 'Explicit: 9', 'logged' => false]],
            InjectedScoreJob::$handled,
        );
    }

    public function testAutowireAttributesWorkOnAtomJobHandleParameters(): void
    {
        $container = $this->buildContainer();
        $container->setParameter('app.job_tag', 'season-4');
        $container->register('app.custom_formatter', ScoreFormatter::class)
            ->setArguments(['Rank'])
            ->setPublic(false);
        $container->register(AutowireAttributeJob::class, AutowireAttributeJob::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setPublic(false);
        $container->compile();

        $handler = $container->get('test.atom_job_handler');
        self::assertInstanceOf(AtomJobHandler::class, $handler);

        $handler(new AtomJobMessage(AutowireAttributeJob::class, ['score' => 3]));

        self::assertSame(
            [['formatted' => 'Rank: 3', 'tag' => 'season-4']],
            AutowireAttributeJob::$handled,
        );
    }

    public function testServiceBindingsApplyToAtomJobHandleParameters(): void
    {
        $container = $this->buildContainer();
        $container->register('app.bound_formatter', ScoreFormatter::class)
            ->setArguments(['Bound'])
            ->setPublic(false);
        $container->register(InjectedScoreJob::class, InjectedScoreJob::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setBindings([
                '$formatter' => new BoundArgument(new Reference('app.bound_formatter')),
                '$multiplier' => new BoundArgument(3),
            ])
            ->setPublic(false);
        $container->compile();

        $handler = $container->get('test.atom_job_handler');
        self::assertInstanceOf(AtomJobHandler::class, $handler);

        $handler(new AtomJobMessage(InjectedScoreJob::class, ['playerId' => 'sam', 'score' => 4]));

        self::assertSame(
            [['playerId' => 'sam', 'formatted' => 'Bound: 12', 'logged' => false]],
            InjectedScoreJob::$handled,
        );
    }

    public function testMissingRequiredDependencyCompilesAndThrowsClearRuntimeErrorWhenInvoked(): void
    {
        $container = $this->buildContainer();
        $container->register(UnregisteredDependencyJob::class, UnregisteredDependencyJob::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setPublic(false);
        $container->compile();

        $handler = $container->get('test.atom_job_handler');
        self::assertInstanceOf(AtomJobHandler::class, $handler);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/UnregisteredDependencyJob::handle\(\)/');

        $handler(new AtomJobMessage(UnregisteredDependencyJob::class, ['id' => 'j-1']));
    }

    public function testAbstractAtomJobInContainerIsIgnored(): void
    {
        $container = $this->buildContainer();
        $container->register(AbstractBaseJob::class, AbstractBaseJob::class)
            ->setAbstract(true)
            ->setAutoconfigured(true);
        $container->compile();

        self::assertInstanceOf(AtomJobHandler::class, $container->get('test.atom_job_handler'));
    }

    /**
     * Full Symfony Kernel end-to-end: FrameworkBundle (with real Messenger
     * enabled) + AtomsBundle, proving MessengerBridgePass runs before
     * FrameworkBundle's MessengerPass so AtomJobHandler is discovered by the
     * real bus AND AtomJobPass autowires handle() dependencies from the
     * compiled container.
     */
    public function testFullSymfonyKernelMessengerEndToEndInjectsHandleDependencies(): void
    {
        $cacheDir = $this->tempDir . '/kernel';
        $secret = self::SECRET;

        $kernel = new class ($cacheDir, $secret) extends Kernel {
            public function __construct(
                private readonly string $cachePath,
                private readonly string $secret,
            ) {
                parent::__construct('test_' . bin2hex(random_bytes(8)), false);
            }

            public function registerBundles(): iterable
            {
                yield new FrameworkBundle();
                yield new AtomsBundle();
            }

            public function getCacheDir(): string
            {
                return $this->cachePath . '/cache';
            }

            public function getBuildDir(): string
            {
                return $this->cachePath . '/cache';
            }

            public function getLogDir(): string
            {
                return $this->cachePath . '/log';
            }

            public function registerContainerConfiguration(LoaderInterface $loader): void
            {
                $loader->load(function (ContainerBuilder $container): void {
                    $container->loadFromExtension('framework', [
                        'secret' => 'job-di-test',
                        'test' => true,
                        'http_method_override' => false,
                        'messenger' => ['enabled' => true],
                    ]);
                    $container->loadFromExtension('atoms', [
                        'endpoint' => 'https://atoms.example.workers.dev',
                        'shared_secret' => $this->secret,
                    ]);
                    $container->register('logger', NullLogger::class)->setPublic(false);
                    $container->register(ScoreFormatter::class, ScoreFormatter::class)
                        ->setArguments(['KernelScore'])
                        ->setPublic(false);
                    $container->register(InjectedScoreJob::class, InjectedScoreJob::class)
                        ->setAutowired(true)
                        ->setAutoconfigured(true)
                        ->setPublic(false);
                });
            }
        };

        $this->kernels[] = $kernel;
        $kernel->boot();

        $bridge = $kernel->getContainer()->get(QueueBridge::class);
        self::assertInstanceOf(QueueBridge::class, $bridge);

        $bridge->enqueue(new InjectedScoreJob(playerId: 'ada', score: 100));

        self::assertSame(
            [['playerId' => 'ada', 'formatted' => 'KernelScore: 100', 'logged' => true]],
            InjectedScoreJob::$handled,
        );
    }
}
