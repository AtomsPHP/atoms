<?php

declare(strict_types=1);

namespace Atoms\Laravel;

use Atoms\Client\AtomsClient;
use Atoms\Client\AtomsConfig;
use Atoms\Client\Callback\CallbackKernel;
use Atoms\Client\Callback\HmacVerifier;
use Atoms\Client\Callback\InMemoryNonceStore;
use Atoms\Client\Callback\MethodsResolver;
use Atoms\Client\Callback\NonceStore;
use Atoms\Client\Callback\QueueBridge;
use Atoms\Client\Manifest\ManifestLoader;
use Atoms\Laravel\Console\DeployCommand;
use Atoms\Laravel\Console\DevCommand;
use Atoms\Laravel\Console\InstallCommand;
use Atoms\Laravel\Console\ListCommand;
use Atoms\Laravel\Console\MakeAtomCommand;
use Atoms\Laravel\Console\RollbackCommand;
use Atoms\Laravel\Http\CallbackController;
use Atoms\Laravel\Queue\LaravelQueueBridge;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Wires atoms/client into a Laravel app: config, the PSR-18/17 plumbing the
 * client needs, {@see AtomsManager} (the facade root), the inbound callback
 * route, and the Artisan wrappers. Nothing here re-implements transport,
 * signing, or serialization — that all stays in atoms/client and atoms/core
 * (docs/integration-plan.md §11).
 */
final class AtomsServiceProvider extends ServiceProvider
{
    private const CONFIG_PATH = __DIR__ . '/../config/atoms.php';

    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG_PATH, 'atoms');

        $this->registerHttpClient();
        $this->registerHttpFactories();
        $this->registerAtomsConfig();
        $this->registerCallbackStack();

        $this->app->singleton(AtomsClient::class, static function (Application $app): AtomsClient {
            return new AtomsClient(
                $app->make(AtomsConfig::class),
                $app->make(ClientInterface::class),
                $app->make(RequestFactoryInterface::class),
                $app->make(StreamFactoryInterface::class),
                $app->bound(LoggerInterface::class) ? $app->make(LoggerInterface::class) : null,
            );
        });

        $this->app->singleton(AtomsManager::class, static fn (Application $app): AtomsManager => new AtomsManager(
            $app->make(AtomsClient::class),
        ));
    }

    public function boot(): void
    {
        $this->publishes([self::CONFIG_PATH => $this->app->configPath('atoms.php')], 'atoms-config');

        $this->bootCallbackRoute();

        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
                DeployCommand::class,
                RollbackCommand::class,
                ListCommand::class,
                DevCommand::class,
                MakeAtomCommand::class,
            ]);
        }
    }

    private function registerHttpClient(): void
    {
        // Capture whether something (host app, another provider) already
        // bound a PSR-18 client BEFORE we touch the binding, so our own
        // registration can defer to it. See config('atoms.http_client') for
        // the explicit override, which always wins.
        $hadPriorBinding = $this->app->bound(ClientInterface::class);
        $configuredOverride = $this->app['config']->get('atoms.http_client');

        if ($hadPriorBinding && $configuredOverride === null) {
            return;
        }

        $this->app->singleton(ClientInterface::class, static function (Application $app): ClientInterface {
            $override = $app['config']->get('atoms.http_client');

            if (is_string($override) && $override !== '') {
                /** @var ClientInterface $client */
                $client = $app->make($override);

                return $client;
            }

            if (class_exists(\GuzzleHttp\Client::class)) {
                return new \GuzzleHttp\Client();
            }

            throw new \RuntimeException(
                'No PSR-18 HTTP client available for Atoms: install guzzlehttp/guzzle, bind '
                . 'Psr\\Http\\Client\\ClientInterface yourself before AtomsServiceProvider boots, '
                . "or set config('atoms.http_client') to a container binding id.",
            );
        });
    }

    private function registerHttpFactories(): void
    {
        $this->app->singleton(HttpFactory::class, static fn (): HttpFactory => new HttpFactory());

        foreach ([RequestFactoryInterface::class, ResponseFactoryInterface::class, StreamFactoryInterface::class] as $interface) {
            $this->app->bind($interface, static fn (Application $app) => $app->make(HttpFactory::class));
        }
    }

    private function registerAtomsConfig(): void
    {
        $this->app->singleton(AtomsConfig::class, function (Application $app): AtomsConfig {
            /** @var array<string, mixed> $config */
            $config = (array) $app['config']->get('atoms', []);

            $manifestPath = $config['manifest_path'] ?? null;

            $previous = $config['shared_secret_previous'] ?? null;

            return AtomsConfig::fromArray([
                'endpoint' => $config['endpoint'] ?? '',
                'sharedSecret' => (string) ($config['shared_secret'] ?? ''),
                // Null means "no rotation in progress". AtomsConfig validates
                // it exactly like the current secret when it is set.
                'sharedSecretPrevious' => is_string($previous) && $previous !== '' ? $previous : null,
                'timeout' => $config['timeout'] ?? 10.0,
                'maxAttempts' => $config['max_attempts'] ?? 3,
                'manifestPath' => is_string($manifestPath) ? $this->resolvePath($manifestPath) : null,
                'environment' => $config['environment'] ?? 'production',
            ]);
        });
    }

    private function registerCallbackStack(): void
    {
        $this->app->singleton(NonceStore::class, static fn (): NonceStore => new InMemoryNonceStore());

        $this->app->singleton(MethodsResolver::class, function (Application $app): MethodsResolver {
            $resolver = new MethodsResolver();

            $manifestPath = $app['config']->get('atoms.manifest_path');
            $fullPath = is_string($manifestPath) ? $this->resolvePath($manifestPath) : null;

            if ($fullPath !== null && is_file($fullPath)) {
                try {
                    $manifest = (new ManifestLoader())->load($fullPath);

                    $typeMap = [];
                    foreach ($manifest->atoms as $atom) {
                        if ($atom->class !== '') {
                            $typeMap[$atom->type !== '' ? $atom->type : $atom->class] = $atom->class;
                        }
                    }

                    $resolver->registerTypeMap($typeMap);
                } catch (\Throwable) {
                    // Best-effort: an unreadable/invalid manifest just means the
                    // convention-based lookup in MethodsResolver::resolve() is
                    // used instead of a manifest-derived type map.
                }
            }

            return $resolver;
        });

        $this->app->singleton(QueueBridge::class, static fn (Application $app): QueueBridge => new LaravelQueueBridge(
            $app->make(Dispatcher::class),
        ));

        $this->app->singleton(CallbackKernel::class, static function (Application $app): CallbackKernel {
            // The kernel verifies with keys derived from the shared secret, and
            // accepts the previous secret's key too while a rotation overlap is
            // configured.
            $config = $app->make(AtomsConfig::class);

            return new CallbackKernel(
                new HmacVerifier($config->callbackKeys()),
                $app->make(NonceStore::class),
                $app->make(MethodsResolver::class),
                $app->make(QueueBridge::class),
                $app->make(ResponseFactoryInterface::class),
                $app->make(StreamFactoryInterface::class),
                (int) $app['config']->get('atoms.callback_timestamp_window', 300),
                $app,
                logger: $app->bound(LoggerInterface::class) ? $app->make(LoggerInterface::class) : null,
            );
        });
    }

    private function bootCallbackRoute(): void
    {
        /** @var array{path?: string, middleware?: list<string>} $callback */
        $callback = (array) $this->app['config']->get('atoms.callback', []);

        Route::post($callback['path'] ?? '/atoms/callback', CallbackController::class)
            ->name('atoms.callback')
            ->middleware($callback['middleware'] ?? []);
    }

    /**
     * Resolve a config path relative to the app base path, lazily (at
     * binding time, not config-load time) so it tracks the app's actual base
     * path in every environment, including tests with a different one.
     */
    private function resolvePath(string $path): string
    {
        if ($path === '') {
            return $path;
        }

        return $this->isAbsolute($path) ? $path : $this->app->basePath($path);
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1;
    }
}
