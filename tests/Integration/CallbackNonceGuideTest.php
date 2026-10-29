<?php

declare(strict_types=1);

namespace Atoms\Tests\Integration;

use App\Callbacks\DatabaseNonceStore;
use Atoms\Client\Callback\CallbackKernel;
use Atoms\Client\Callback\HmacVerifier;
use Atoms\Client\Callback\MethodsResolver;
use Atoms\Client\Callback\NonceStore;
use Atoms\Laravel\AtomsServiceProvider;
use Atoms\Symfony\AtomsBundle;
use Atoms\Tests\Integration\Adapters\Fixtures\RecordScoreJob;
use Atoms\Tests\Integration\Adapters\Support\RecordingQueueBridge;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\ServerRequest;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

final class CallbackNonceGuideTest extends TestCase
{
    private string $databasePath;

    public static function setUpBeforeClass(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'atoms-nonce-class-');
        file_put_contents($file, self::snippet('guides/callbacks.md', 'DatabaseNonceStore.php'));
        try {
            require $file;
        } finally {
            unlink($file);
        }
    }

    protected function setUp(): void
    {
        $this->databasePath = tempnam(sys_get_temp_dir(), 'atoms-nonce-db-');
        $this->connection()->exec(self::snippet('guides/callbacks.md', 'callback_nonces.sql'));
    }

    protected function tearDown(): void
    {
        unlink($this->databasePath);
    }

    public static function hosts(): iterable
    {
        yield 'Laravel binding' => ['laravel'];
        yield 'Symfony alias' => ['symfony'];
    }

    #[DataProvider('hosts')]
    public function testFreshContainersRejectTheSameSignedCallback(string $host): void
    {
        $queue = new RecordingQueueBridge();
        $request = $this->request();
        $before = time();
        $first = $this->kernel($this->store($host), $queue);
        self::assertSame(200, $first->handle($request)->getStatusCode());
        unset($first);

        $second = $this->kernel($this->store($host), $queue);
        $response = $second->handle($request);
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('ATOMS-E065', json_decode((string) $response->getBody(), true)['error']['code']);
        self::assertCount(1, $queue->jobs);
        $expires = (int) $this->connection()->query('SELECT expires_at FROM atoms_callback_nonces')->fetchColumn();
        self::assertGreaterThanOrEqual($before + 900, $expires);
    }

    public function testDatabaseFailureDoesNotQueueTheJob(): void
    {
        $queue = new RecordingQueueBridge();
        $kernel = $this->kernel(new DatabaseNonceStore($this->connection(), 900), $queue);
        $this->connection()->exec('DROP TABLE atoms_callback_nonces');
        try {
            $kernel->handle($this->request());
            self::fail('A storage failure must stop the callback.');
        } catch (\PDOException) {
            self::assertSame([], $queue->jobs);
        }
    }

    public function testAnApplicationTransactionCannotOwnTheNonceRecord(): void
    {
        $connection = $this->connection();
        $store = new DatabaseNonceStore($connection, 900);
        $connection->beginTransaction();
        try {
            $store->seen('nonce');
            self::fail('A nonce record must not be rolled back with application work.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('autocommit', $error->getMessage());
        } finally {
            $connection->rollBack();
        }
    }

    private function store(string $host): NonceStore
    {
        if ($host === 'laravel') {
            $app = new Application(dirname(__DIR__, 2));
            $app->instance('config', new Repository(['atoms_callbacks' => [
                'dsn' => 'sqlite:' . $this->databasePath,
                'username' => null,
                'password' => null,
                'retention_seconds' => 900,
            ]]));
            (new AtomsServiceProvider($app))->register();
            $binding = self::snippet('getting-started/laravel.md', 'app/Providers/AppServiceProvider.php');
            $file = tempnam(sys_get_temp_dir(), 'atoms-nonce-binding-');
            file_put_contents($file, '<?php return new class($app) extends \\' . ServiceProvider::class
                . ' { public function register(): void { ' . $binding . ' } };');
            try {
                $provider = require $file;
                $provider->register();
                return $app->make(NonceStore::class);
            } finally {
                unlink($file);
            }
        }

        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());
        foreach ([
            'ATOMS_NONCE_DSN' => 'sqlite:' . $this->databasePath,
            'ATOMS_NONCE_DB_USER' => '',
            'ATOMS_NONCE_DB_PASSWORD' => '',
            'ATOMS_NONCE_RETENTION_SECONDS' => '900',
        ] as $name => $value) {
            $container->setParameter('env(' . $name . ')', $value);
        }
        $bundle = new AtomsBundle();
        $bundle->build($container);
        $extension = $bundle->getContainerExtension();
        $container->registerExtension($extension);
        $container->loadFromExtension($extension->getAlias(), [
            'endpoint' => 'https://example.test',
            'shared_secret' => base64_encode(str_repeat('a', 32)),
        ]);
        $file = tempnam(sys_get_temp_dir(), 'atoms-nonce-services-');
        file_put_contents($file, self::snippet('getting-started/symfony.md', 'config/services.yaml'));
        try {
            (new YamlFileLoader($container, new FileLocator()))->load($file);
            $container->getAlias(NonceStore::class)->setPublic(true);
            $container->compile(true);
            return $container->get(NonceStore::class);
        } finally {
            unlink($file);
        }
    }

    private function connection(): PDO
    {
        return new PDO('sqlite:' . $this->databasePath);
    }

    private function kernel(NonceStore $store, RecordingQueueBridge $queue): CallbackKernel
    {
        $factory = new HttpFactory();
        return new CallbackKernel(
            new HmacVerifier([str_repeat('a', 32)]), $store, new MethodsResolver(), $queue, $factory, $factory,
        );
    }

    private function request(): ServerRequest
    {
        $body = json_encode(['job' => RecordScoreJob::class, 'args' => ['playerId' => 'ada', 'score' => 1]], JSON_THROW_ON_ERROR);
        // Exercise a future timestamp, which remains valid longer than one window after receipt.
        $timestamp = (string) (time() + 300);
        $nonce = 'guide-test-nonce';
        return new ServerRequest('POST', 'https://example.test/atoms/callback', [
            'X-Atoms-Kind' => 'job',
            'X-Atoms-Timestamp' => $timestamp,
            'X-Atoms-Nonce' => $nonce,
            'X-Atoms-Signature' => base64_encode(hash_hmac('sha256', "v1\n$timestamp\n$nonce\n$body", str_repeat('a', 32), true)),
        ], $body);
    }

    private static function snippet(string $page, string $title): string
    {
        $guide = file_get_contents(dirname(__DIR__, 2) . '/docs-site/src/content/docs/' . $page);
        self::assertSame(1, preg_match('/```\w+ title="' . preg_quote($title, '/') . '"\n(.*?)\n```/s', $guide, $matches));
        return $matches[1];
    }
}
