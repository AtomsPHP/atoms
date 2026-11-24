<?php

declare(strict_types=1);

namespace Atoms\Symfony\Tests\Messenger;

use Atoms\AtomJob;
use Atoms\Client\Callback\QueueBridge;
use Atoms\Symfony\AtomsBundle;
use Atoms\Symfony\Messenger\AtomJobHandler;
use Atoms\Symfony\Messenger\AtomJobMessage;
use Atoms\Symfony\Messenger\MessengerQueueBridge;
use Atoms\Symfony\Tests\Fixtures\TimestampJob;
use Atoms\Symfony\Tests\Support\FakePsr18Client;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Kernel;

final class AtomJobDependencyInjectionTest extends TestCase
{
    public function testStandaloneHandlerSuppliesNullForAnUnresolvedNullableArgument(): void
    {
        $job = new class extends AtomJob {
            public function handle(?\DateInterval $offset): void
            {
                TestCase::assertNull($offset);
            }
        };

        (new AtomJobHandler())(new AtomJobMessage($job::class, []));
    }

    #[DataProvider('jobRegistration')]
    public function testMessengerResolvesHandleArguments(bool $explicitRegistration, bool $missingClock): void
    {
        $exceptionHandler = get_exception_handler();
        $errorHandler = get_error_handler();
        $directory = sys_get_temp_dir() . '/atoms-jobs-' . bin2hex(random_bytes(8));
        $kernel = new JobTestKernel($directory, $explicitRegistration, $missingClock);
        try {
            if ($missingClock) {
                $this->expectException(ServiceNotFoundException::class);
                $this->expectExceptionMessage(\DateTimeInterface::class);
            }

            $kernel->boot();
            $container = $kernel->getContainer();
            $queue = $container->get(QueueBridge::class);
            self::assertInstanceOf(MessengerQueueBridge::class, $queue);

            $queue->enqueue(new TimestampJob('queued'));

            $logger = $container->get('test.job_logger');
            self::assertInstanceOf(JobLogger::class, $logger);
            self::assertSame(['queued 20:00 14:15'], $logger->messages);
        } finally {
            $kernel->shutdown();
            (new Filesystem())->remove($directory);
            if (get_exception_handler() !== $exceptionHandler) {
                restore_exception_handler();
            }
            if (get_error_handler() !== $errorHandler) {
                restore_error_handler();
            }
        }
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function jobRegistration(): iterable
    {
        yield 'discovered service' => [false, false];
        yield 'configured job class' => [true, false];
        yield 'missing required interface alias' => [true, true];
    }
}

final class JobTestKernel extends Kernel
{
    public function __construct(
        private readonly string $directory,
        private readonly bool $explicitRegistration,
        private readonly bool $missingClock,
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
        return $this->directory;
    }

    public function getLogDir(): string
    {
        return $this->directory . '/log';
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(function (ContainerBuilder $container): void {
            $container->loadFromExtension('framework', [
                'secret' => 'job-test',
                'messenger' => ['enabled' => true],
            ]);
            $container->loadFromExtension('atoms', [
                'endpoint' => 'https://example.test',
                'shared_secret' => base64_encode(str_repeat('a', 32)),
                'http_client' => 'test.psr18_client',
            ] + ($this->explicitRegistration ? ['job_classes' => [TimestampJob::class]] : []));
            $container->register('test.psr18_client', FakePsr18Client::class);
            if (!$this->missingClock) {
                $container->register('test.clock', \DateTimeImmutable::class)->setArguments(['2024-04-01T18:00:00Z']);
                $container->setAlias(\DateTimeInterface::class, 'test.clock');
            }
            $container->register('test.paris', \DateTimeZone::class)->setArguments(['Europe/Paris']);
            $container->register('test.new_york', \DateTimeZone::class)->setArguments(['America/New_York']);
            $container->registerAliasForArgument('test.new_york', \DateTimeZone::class, 'publishZone');
            $container->register('test.job_logger', JobLogger::class)->setPublic(true);
            $container->setAlias(LoggerInterface::class, 'test.job_logger');
            if (!$this->explicitRegistration) {
                $container->register(TimestampJob::class, TimestampJob::class)
                    ->setAutowired(true)
                    ->setAutoconfigured(true);
            }
        });
    }
}

final class JobLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $messages = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->messages[] = (string) $message;
    }
}
