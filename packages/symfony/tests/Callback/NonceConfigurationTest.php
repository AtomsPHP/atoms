<?php

declare(strict_types=1);

namespace Atoms\Symfony\Tests\Callback;

use Atoms\Client\Callback\CallbackKernel;
use Atoms\Client\Callback\InMemoryNonceStore;
use Atoms\Client\Callback\NonceStore;
use Atoms\Client\Crypto\KeyDerivation;
use Atoms\Symfony\AtomsBundle;
use Atoms\Symfony\Callback\DoctrineNonceStore;
use Atoms\Symfony\Migrations\Version20261029000000;
use Atoms\Symfony\Tests\Fixtures\GameRoom;
use Atoms\Symfony\Tests\Support\NonceTestKernel;
use Doctrine\Migrations\DependencyFactory;
use GuzzleHttp\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Filesystem\Filesystem;

final class NonceConfigurationTest extends TestCase
{
    private string $directory;
    private mixed $baselineExceptionHandler;
    private mixed $baselineErrorHandler;
    /** @var list<NonceTestKernel> */
    private array $kernels = [];

    protected function setUp(): void
    {
        $this->baselineExceptionHandler = get_exception_handler();
        $this->baselineErrorHandler = get_error_handler();
        $this->directory = sys_get_temp_dir() . '/atoms-nonce-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach ($this->kernels as $kernel) {
            $kernel->shutdown();
        }
        (new Filesystem())->remove($this->directory);
        for ($attempt = 0; get_exception_handler() !== $this->baselineExceptionHandler && $attempt < 64; $attempt++) {
            restore_exception_handler();
        }
        for ($attempt = 0; get_error_handler() !== $this->baselineErrorHandler && $attempt < 64; $attempt++) {
            restore_error_handler();
        }
    }

    public function testMigrationAndCallbacksAcrossIndependentContainers(): void
    {
        $first = $this->kernel();
        $application = new Application($first);
        $migrate = new CommandTester($application->find('doctrine:migrations:migrate'));
        self::assertSame(0, $migrate->execute([], ['interactive' => false]), $migrate->getDisplay());
        $testContainer = $first->getContainer()->get('test.service_container');
        $migrations = $testContainer->get('doctrine.migrations.dependency_factory');
        self::assertInstanceOf(DependencyFactory::class, $migrations);
        self::assertTrue($migrations->getMigrationRepository()->getMigrations()->hasMigration(new \Doctrine\Migrations\Version\Version(Version20261029000000::class)));
        self::assertSame(1, (int) $migrations->getConnection()->fetchOne('SELECT COUNT(*) FROM doctrine_migration_versions'));

        $request = $this->request();
        self::assertSame(200, $first->getContainer()->get(CallbackKernel::class)->handle($request)->getStatusCode());
        $first->shutdown();
        $second = $this->kernel();
        self::assertSame(401, $second->getContainer()->get(CallbackKernel::class)->handle($request)->getStatusCode());

        $prune = new CommandTester((new Application($second))->find('atoms:nonces:prune'));
        self::assertSame(0, $prune->execute([]));
        self::assertStringContainsString('Deleted 0', $prune->getDisplay());

        $rollback = new CommandTester((new Application($second))->find('doctrine:migrations:execute'));
        self::assertSame(0, $rollback->execute([
            'versions' => [Version20261029000000::class],
            '--down' => true,
        ], ['interactive' => false]), $rollback->getDisplay());
        $connection = $second->getContainer()->get('test.service_container')
            ->get('doctrine.migrations.dependency_factory')->getConnection();
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM sqlite_master WHERE name = ?', [DoctrineNonceStore::TABLE]));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM doctrine_migration_versions'));
    }

    public function testSchemaFilterKeepsApplicationRulesAndExcludesNonceTable(): void
    {
        $kernel = $this->kernel();
        $container = $kernel->getContainer()->get('test.service_container');
        $migrations = $container->get('doctrine.migrations.dependency_factory');
        $filter = $migrations->getConnection()->getConfiguration()->getSchemaAssetsFilter();
        self::assertIsCallable($filter);
        self::assertFalse($filter(DoctrineNonceStore::TABLE));
        self::assertFalse($filter('application_hidden'));
        self::assertTrue($filter('application_visible'));
    }

    public function testEnvironmentDriverRegistersMigrationAndStaysConsistentWithCompiledServices(): void
    {
        $_ENV['ATOMS_TEST_NONCE_DRIVER'] = 'database';
        try {
            $kernel = $this->kernel(['driver' => '%env(ATOMS_TEST_NONCE_DRIVER)%', 'namespace' => 'test']);
            $_ENV['ATOMS_TEST_NONCE_DRIVER'] = 'memory';
            $application = new Application($kernel);
            $migrate = new CommandTester($application->find('doctrine:migrations:migrate'));
            self::assertSame(0, $migrate->execute([], ['interactive' => false]), $migrate->getDisplay());
            self::assertSame(200, $kernel->getContainer()->get(CallbackKernel::class)->handle($this->request())->getStatusCode());
            $migrations = $kernel->getContainer()->get('test.service_container')->get('doctrine.migrations.dependency_factory');
            self::assertSame(1, (int) $migrations->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . DoctrineNonceStore::TABLE));
            self::assertSame('atoms:nonces:prune', $application->find('atoms:nonces:prune')->getName());
        } finally {
            unset($_ENV['ATOMS_TEST_NONCE_DRIVER']);
        }
    }

    public function testNoStoreRejectsSignedCallbacksButNotAuthenticationFirst(): void
    {
        $container = $this->bareContainer();
        $container->compile();
        $callback = $container->get(CallbackKernel::class);
        self::assertSame(401, $callback->handle($this->request()->withoutHeader('X-Atoms-Signature'))->getStatusCode());
        $response = $callback->handle($this->request());
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('ATOMS-E111', json_decode((string) $response->getBody(), true)['error']['code']);
    }

    public function testCustomNonceStoreOverridesUnconfiguredDefault(): void
    {
        $container = $this->bareContainer();
        $container->register('custom.nonce_store', InMemoryNonceStore::class);
        $container->setAlias(NonceStore::class, 'custom.nonce_store');
        $container->compile();
        self::assertSame(200, $container->get(CallbackKernel::class)->handle($this->request())->getStatusCode());
    }

    public function testRedisConnectionIsNotConstructedForInvalidSignature(): void
    {
        $container = $this->bareContainer(['driver' => 'redis', 'namespace' => 'test', 'redis_service' => 'throwing.redis']);
        $container->register('throwing.redis', ThrowingRedisService::class);
        $container->compile();
        $callback = $container->get(CallbackKernel::class);
        self::assertSame(401, $callback->handle($this->request()->withoutHeader('X-Atoms-Signature'))->getStatusCode());
        self::assertSame(503, $callback->handle($this->request())->getStatusCode());
    }

    public function testConfiguredPredisServiceClaimsNonceAndRejectsReplay(): void
    {
        $connection = $this->createMock(\Predis\Connection\ConnectionInterface::class);
        $connection->method('getParameters')->willReturn(new \Predis\Connection\Parameters());
        $connection->expects(self::exactly(2))->method('executeCommand')
            ->with(self::callback(static function (\Predis\Command\CommandInterface $command): bool {
                self::assertSame('EVAL', $command->getId());
                $arguments = $command->getArguments();
                self::assertSame(1, $arguments[1]);
                self::assertSame('atoms:callback-nonce:' . hash('sha256', "test\0same-nonce"), $arguments[2]);
                self::assertSame('661', $arguments[3]);
                return true;
            }))->willReturnOnConsecutiveCalls(1, 0);
        $container = $this->bareContainer(['driver' => 'redis', 'namespace' => 'test', 'redis_service' => 'app.redis']);
        $container->register('app.redis', \Predis\Client::class)->setSynthetic(true)->setPublic(true);
        $container->compile();
        $container->set('app.redis', new \Predis\Client($connection));
        $callback = $container->get(CallbackKernel::class);
        self::assertSame(200, $callback->handle($this->request())->getStatusCode());
        self::assertSame(401, $callback->handle($this->request())->getStatusCode());
    }

    public function testDatabaseWithoutMigrationsBundleRejectsCallbacksLazily(): void
    {
        $container = $this->bareContainer(['driver' => 'database', 'namespace' => 'test']);
        $container->compile();
        self::assertSame(503, $container->get(CallbackKernel::class)->handle($this->request())->getStatusCode());
    }

    /** @param array<string, mixed> $nonceConfig */
    private function kernel(array $nonceConfig = ['driver' => 'database', 'namespace' => 'test']): NonceTestKernel
    {
        $kernel = new NonceTestKernel($this->directory . '/nonces.sqlite', $this->directory . '/cache-' . count($this->kernels), $nonceConfig);
        $this->kernels[] = $kernel;
        $kernel->boot();
        return $kernel;
    }

    /** @param array<string, mixed> $nonceConfig */
    private function bareContainer(array $nonceConfig = []): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', $this->directory);
        $bundle = new AtomsBundle();
        $bundle->build($container);
        $extension = $bundle->getContainerExtension();
        $container->registerExtension($extension);
        $container->loadFromExtension('atoms', [
            'endpoint' => 'https://example.test',
            'shared_secret' => base64_encode(str_repeat('a', 32)),
            'callback_nonce' => $nonceConfig,
        ]);
        return $container;
    }

    private function request(): ServerRequest
    {
        $body = json_encode(['atom' => ['type' => GameRoom::class, 'id' => 'room'], 'method' => 'add', 'args' => [2, 3]], JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $nonce = 'same-nonce';
        $key = KeyDerivation::callbackKey(base64_encode(str_repeat('a', 32)));
        return new ServerRequest('POST', 'https://example.test/atoms/callback', [
            'X-Atoms-Kind' => 'methods',
            'X-Atoms-Timestamp' => $timestamp,
            'X-Atoms-Nonce' => $nonce,
            'X-Atoms-Signature' => base64_encode(hash_hmac('sha256', "v1\n$timestamp\n$nonce\n$body", $key, true)),
        ], $body);
    }
}

final class ThrowingRedisService
{
    public function __construct()
    {
        throw new \RuntimeException('Connection unavailable.');
    }
}
