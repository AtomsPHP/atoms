<?php

declare(strict_types=1);

namespace Atoms\Tests\Integration\Adapters\Host;

use Atoms\Client\AtomsConfig;
use Atoms\Client\Callback\MethodsResolver;
use Atoms\Client\Callback\NonceStore;
use Atoms\Laravel\AtomsServiceProvider;
use Atoms\Laravel\Queue\AtomJobEnvelope;
use Atoms\Tests\Integration\Adapters\Support\FakePsr18Client;
use Atoms\Tests\Integration\Adapters\Support\RecordingLogger;
use Illuminate\Container\Container;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Facade;
use Orchestra\Testbench\Foundation\Application as TestbenchApplication;
use PHPUnit\Framework\Assert;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;

use function Orchestra\Testbench\parse_environment_variables;

/**
 * Boots a real Laravel application — {@see TestbenchApplication::create()}
 * registering the real {@see AtomsServiceProvider}, driven through the real
 * `Illuminate\Contracts\Http\Kernel` — so this suite exercises the SAME
 * config/atoms.php -> env() chain, route registration and queue wiring a host
 * app would, never a reimplementation of any of it.
 */
final class LaravelHost implements AdapterHost
{
    private const ENV_KEYS = [
        'ATOMS_ENDPOINT',
        'ATOMS_SHARED_SECRET',
        'ATOMS_SHARED_SECRET_PREVIOUS',
        'ATOMS_CALLBACK_PATH',
    ];

    private ?Application $app = null;

    private RecordingLogger $logger;

    private FakePsr18Client $http;

    private ?Request $lastRequest = null;

    private ?\Symfony\Component\HttpFoundation\Response $lastResponse = null;

    public function name(): string
    {
        return 'laravel';
    }

    public function boot(HostOptions $options): void
    {
        if ($options->queueAvailable === false) {
            // Laravel always has a bus: AtomsServiceProvider::registerCallbackStack()
            // binds QueueBridge::class to LaravelQueueBridge unconditionally
            // (it wraps Illuminate\Contracts\Bus\Dispatcher, which every
            // Laravel app has regardless of queue configuration). There is no
            // config-driven way to make this host boot queueless, so S4's
            // premise ("no queue wired") cannot be represented here — skip
            // via the same mechanism AdapterConformanceTestCase's own
            // capability gates use.
            Assert::markTestSkipped(
                "Host 'laravel' cannot boot queueless: LaravelQueueBridge wraps "
                . 'Illuminate\\Contracts\\Bus\\Dispatcher unconditionally, so Laravel always has a bus.',
            );
        }

        $this->logger = new RecordingLogger();
        $this->http = new FakePsr18Client();
        $this->lastRequest = null;
        $this->lastResponse = null;

        $this->clearEnv();

        $envAssoc = [
            'ATOMS_ENDPOINT' => $options->endpoint,
            'ATOMS_SHARED_SECRET' => $options->sharedSecret,
            'ATOMS_CALLBACK_PATH' => $options->callbackPath,
        ];

        // Omitted entirely (not set to '') when null: env('ATOMS_SHARED_SECRET_PREVIOUS')
        // must come back null, not the empty string, when no overlap secret
        // is configured (see config/atoms.php's shared_secret_previous line
        // and AtomsConfig).
        if (is_string($options->sharedSecretPrevious)) {
            $envAssoc['ATOMS_SHARED_SECRET_PREVIOUS'] = $options->sharedSecretPrevious;
        }

        $methodsClasses = $options->methodsClasses;
        $nonceStore = $options->nonceStore;
        $containerBindings = $options->containerBindings;

        $this->app = TestbenchApplication::create(
            resolvingCallback: function (Application $app) use ($methodsClasses, $nonceStore, $containerBindings): void {
                // Bind test doubles BEFORE AtomsServiceProvider builds its
                // singletons — registerHttpClient() explicitly defers to a
                // prior ClientInterface binding, so this is the same
                // registration order a host app doing the same thing would
                // use, not a bypass of it.
                $app->instance(ClientInterface::class, $this->http);
                $app->instance(LoggerInterface::class, $this->logger);

                // NonceStore/MethodsResolver are bound unconditionally by
                // AtomsServiceProvider (no config-driven override point), so
                // extend() — applied once the provider's own singleton is
                // actually built — is how a host app would layer its own
                // NonceStore or extra Methods classes on top.
                if ($nonceStore !== null) {
                    $app->extend(NonceStore::class, static fn (): NonceStore => $nonceStore);
                }

                $app->extend(
                    MethodsResolver::class,
                    static function (MethodsResolver $resolver) use ($methodsClasses): MethodsResolver {
                        foreach ($methodsClasses as $methodsClass) {
                            $resolver->registerMethodsClass($methodsClass);
                        }

                        return $resolver;
                    },
                );

                // S6: whatever this boot's HostOptions::$containerBindings
                // supplies gets bound into THIS SAME $app — the one
                // AtomsServiceProvider::registerCallbackStack() passes
                // straight through as CallbackKernel's container argument
                // (see AtomsServiceProvider::registerCallbackStack()). A real
                // host app reaching for container-backed Methods-class
                // dependencies would bind them the same way, in its own
                // service provider.
                foreach ($containerBindings as $class => $instance) {
                    $app->instance($class, $instance);
                }
            },
            options: [
                'extra' => [
                    'providers' => [AtomsServiceProvider::class],
                    'env' => parse_environment_variables($envAssoc),
                ],
            ],
        );

        // Queue observation: the real LaravelQueueBridge -> Dispatcher path,
        // with Bus::fake() recording every AtomJobEnvelope it dispatches —
        // see queuedJobs().
        Bus::fake();

        // S2: AtomsConfig::class is a deferred singleton — booting the app
        // alone never constructs it. Force it now so a malformed shared
        // secret's throw surfaces here, matching every other host's "throws
        // at construction" contract.
        $this->app->make(AtomsConfig::class);
    }

    public function shutdown(): void
    {
        if ($this->app === null) {
            return;
        }

        if ($this->lastRequest !== null && $this->lastResponse !== null) {
            $this->app->make(HttpKernelContract::class)->terminate($this->lastRequest, $this->lastResponse);
        }

        Facade::clearResolvedInstances();
        Container::setInstance(null);

        // Application::create() installs a global PHP error/exception
        // handler (Orchestra\Testbench\Bootstrap\HandleExceptions extends
        // this same bootstrapper) and never tears it down on its own — a
        // normal Testbench TestCase's own lifecycle does that; since
        // LaravelHost isn't one, it has to do it here, or PHPUnit marks
        // every test that boots this host "risky" for leaking a handler.
        HandleExceptions::flushState();

        $this->app = null;
        $this->lastRequest = null;
        $this->lastResponse = null;

        $this->clearEnv();
    }

    public function handle(HostRequest $request): HostResponse
    {
        $server = [];
        foreach ($request->headers as $name => $value) {
            $server[$this->serverKey($name)] = $value;
        }

        $illuminateRequest = Request::create($request->path, $request->method, [], [], [], $server, $request->body);

        $response = $this->app()->make(HttpKernelContract::class)->handle($illuminateRequest);

        $this->lastRequest = $illuminateRequest;
        $this->lastResponse = $response;

        return new HostResponse(
            $response->getStatusCode(),
            $this->flattenHeaders($response->headers->all()),
            (string) $response->getContent(),
        );
    }

    public function service(string $id): object
    {
        /** @var object $service */
        $service = $this->app()->make($id);

        return $service;
    }

    public function queuedJobs(): array
    {
        return Bus::dispatched(AtomJobEnvelope::class)->all();
    }

    public function logRecords(): array
    {
        return $this->logger->records;
    }

    public function httpFake(): FakePsr18Client
    {
        return $this->http;
    }

    public function supports(string $capability): bool
    {
        return in_array($capability, ['routing', 'container', 'client', 'queue', 'logging'], true);
    }

    private function app(): Application
    {
        return $this->app ?? throw new \LogicException('LaravelHost::handle() called before boot().');
    }

    private function clearEnv(): void
    {
        foreach (self::ENV_KEYS as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }
    }

    /**
     * Mirror the real `$_SERVER` shape for one header: uppercase, dashes
     * become underscores, `HTTP_` prefixed — except Content-Type/
     * Content-Length, which PHP exposes unprefixed. Same mapping
     * PlainPhpHost::serverKey() uses, so the M4 lowercase-header case behaves
     * identically here.
     */
    private function serverKey(string $headerName): string
    {
        $normalized = strtoupper(str_replace('-', '_', $headerName));

        return match ($normalized) {
            'CONTENT_TYPE', 'CONTENT_LENGTH' => $normalized,
            default => 'HTTP_' . $normalized,
        };
    }

    /**
     * @param array<string, list<string>> $headers
     * @return array<string, string>
     */
    private function flattenHeaders(array $headers): array
    {
        $flat = [];
        foreach ($headers as $name => $values) {
            $flat[$name] = implode(', ', $values);
        }

        return $flat;
    }
}
