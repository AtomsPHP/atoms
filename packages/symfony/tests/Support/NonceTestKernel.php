<?php

declare(strict_types=1);

namespace Atoms\Symfony\Tests\Support;

use Atoms\Client\Callback\InMemoryNonceStore;
use Atoms\Client\Callback\NonceStore;
use Atoms\Symfony\AtomsBundle;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;

final class NonceTestKernel extends Kernel
{
    /** @param array<string, mixed> $nonceConfig */
    public function __construct(
        private readonly string $databasePath,
        private readonly string $cachePath,
        private readonly array $nonceConfig = ['driver' => 'database', 'namespace' => 'test'],
        private readonly bool $customNonceStore = false,
    ) {
        // PHP keeps loaded container classes after a kernel and its cache are removed.
        parent::__construct('test_' . bin2hex(random_bytes(8)), true);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new DoctrineBundle();
        yield new DoctrineMigrationsBundle();
        yield new AtomsBundle();
    }

    public function getCacheDir(): string
    {
        return $this->cachePath;
    }

    public function getLogDir(): string
    {
        return $this->cachePath . '/log';
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(function (ContainerBuilder $container): void {
            $container->loadFromExtension('framework', ['secret' => 'nonce-test', 'test' => true]);
            $container->loadFromExtension('doctrine', ['dbal' => [
                'driver' => 'pdo_sqlite',
                'path' => $this->databasePath,
                'schema_filter' => '~^(?!application_hidden)~',
            ]]);
            $container->loadFromExtension('doctrine_migrations', ['connection' => 'default', 'enable_profiler' => false]);
            $container->loadFromExtension('atoms', [
                'endpoint' => 'https://example.test',
                'shared_secret' => base64_encode(str_repeat('a', 32)),
                'callback_nonce' => $this->nonceConfig,
            ]);
            if ($this->customNonceStore) {
                $container->register('custom.nonce_store', InMemoryNonceStore::class);
                $container->setAlias(NonceStore::class, 'custom.nonce_store');
            }
        });
    }
}
